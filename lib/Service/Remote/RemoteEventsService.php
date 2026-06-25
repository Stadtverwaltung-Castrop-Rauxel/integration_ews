<?php
//declare(strict_types=1);

/**
 * @copyright Copyright (c) 2023 Sebastian Krupinski <krupinski01@gmail.com>
 *
 * @author Sebastian Krupinski <krupinski01@gmail.com>
 *
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\EWS\Service\Remote;

use DateInterval;
use Datetime;
use DateTimeZone;
use OCA\EWS\Components\EWS\ArrayType\ArrayOfStringsType;
use OCA\EWS\Components\EWS\ArrayType\ArrayOfTransitionsGroupsType;
use OCA\EWS\Components\EWS\ArrayType\ArrayOfTransitionsType;
use OCA\EWS\Components\EWS\ArrayType\NonEmptyArrayOfAttendeesType;
use OCA\EWS\Components\EWS\ArrayType\NonEmptyArrayOfPathsToElementType;
use OCA\EWS\Components\EWS\ArrayType\NonEmptyArrayOfPeriodsType;
use OCA\EWS\Components\EWS\EWSClient;
use OCA\EWS\Components\EWS\Type\AbsoluteDateTransitionType;
use OCA\EWS\Components\EWS\Type\AbsoluteMonthlyRecurrencePatternType;
use OCA\EWS\Components\EWS\Type\AbsoluteYearlyRecurrencePatternType;
use OCA\EWS\Components\EWS\Type\AttendeeType;
use OCA\EWS\Components\EWS\Type\BodyType;
use OCA\EWS\Components\EWS\Type\CalendarFolderType;
use OCA\EWS\Components\EWS\Type\CalendarItemType;
use OCA\EWS\Components\EWS\Type\DailyRecurrencePatternType;
use OCA\EWS\Components\EWS\Type\DeleteItemFieldType;
use OCA\EWS\Components\EWS\Type\EmailAddressType;
use OCA\EWS\Components\EWS\Type\EndDateRecurrenceRangeType;
use OCA\EWS\Components\EWS\Type\ExtendedPropertyType;
use OCA\EWS\Components\EWS\Type\FileAttachmentType;
use OCA\EWS\Components\EWS\Type\FolderIdType;
use OCA\EWS\Components\EWS\Type\ItemIdType;
use OCA\EWS\Components\EWS\Type\NoEndRecurrenceRangeType;
use OCA\EWS\Components\EWS\Type\NumberedRecurrenceRangeType;
use OCA\EWS\Components\EWS\Type\PathToExtendedFieldType;
use OCA\EWS\Components\EWS\Type\PathToIndexedFieldType;
use OCA\EWS\Components\EWS\Type\PathToUnindexedFieldType;
use OCA\EWS\Components\EWS\Type\PeriodType;
use OCA\EWS\Components\EWS\Type\RecurrenceType;
use OCA\EWS\Components\EWS\Type\RecurringDateTransitionType;
use OCA\EWS\Components\EWS\Type\RecurringDayTransitionType;
use OCA\EWS\Components\EWS\Type\RelativeMonthlyRecurrencePatternType;
use OCA\EWS\Components\EWS\Type\RelativeYearlyRecurrencePatternType;
use OCA\EWS\Components\EWS\Type\SetItemFieldType;
use OCA\EWS\Components\EWS\Type\TimeZoneDefinitionType;
use OCA\EWS\Components\EWS\Type\TransitionTargetType;
use OCA\EWS\Components\EWS\Type\TransitionType;
use OCA\EWS\Components\EWS\Type\WeeklyRecurrencePatternType;
use OCA\EWS\Objects\EventAttachmentObject;
use OCA\EWS\Objects\EventCollectionObject;
use OCA\EWS\Objects\EventObject;
use OCA\EWS\Utils\MIME;
use OCA\EWS\Utils\TimeZoneEWS;
use OCA\EWS\Utils\UUID;
use Psr\Log\LoggerInterface;

class RemoteEventsService
{
    /**
     * @var EWSClient
     */
    protected ?EWSClient $DataStore = null;
    /**
     * @var Object
     */
    protected $Configuration;
    /**
     * @var ?DateTimeZone
     */
    protected ?DateTimeZone $SystemTimeZone = null;
    /**
     * @var ?DateTimeZone
     */
    protected ?DateTimeZone $UserTimeZone = null;
    /**
     * @var Object
     */
    protected ?object $DefaultCollectionProperties = null;
    /**
     * @var Object
     */
    protected ?object $DefaultItemProperties = null;

    /**
     * @psalm-mutation-free
     */
    public function __construct(string                        $appName,
                                protected LoggerInterface     $logger,
                                protected RemoteCommonService $RemoteCommonService)
    {
    }

    /**
     * @psalm-external-mutation-free
     */
    public function configure($configuration, EWSClient $DataStore): void
    {

        // assign configuration
        $this->Configuration = $configuration;
        // assign remote data store
        $this->DataStore = $DataStore;
        // assign timezones
        $this->SystemTimeZone = $configuration->SystemTimeZone;
        $this->UserTimeZone = $configuration->UserTimeZone;

    }

    /**
     * retrieve list of collections in remote storage
     *
     * @param string $source folder source (U - User Folders, P - Public Folders)
     * @param string $prefixName string to append to folder name
     *
     * @return array of collections and properties
     * @since Release 1.0.0
     *
     */
    public function listCollections(string $source = 'U', string $prefixName = ''): array
    {

        // execute command
        $cr = $this->RemoteCommonService->fetchFoldersByType($this->DataStore, 'IPF.Appointment', 'I', $this->constructDefaultCollectionProperties(), $source);
        // process response
        $cl = array();
        if (isset($cr)) {
            foreach ($cr->CalendarFolder as $folder) {
                $cl[] = array('id' => $folder->FolderId->Id, 'name' => $prefixName . $folder->DisplayName, 'count' => $folder->TotalCount);
            }
        }
        // return collections
        return $cl;

    }

    /**
     * retrieve properties for specific collection
     *
     * @param string $cid - Collection Id
     *
     * @return EventCollectionObject
     * @since Release 1.0.0
     *
     */
    public function fetchCollection(string $cid): ?EventCollectionObject
    {

        // execute command
        $cr = $this->RemoteCommonService->fetchFolder($this->DataStore, $cid, false, 'I', $this->constructDefaultCollectionProperties());
        // process response
        if (isset($cr) && (count($cr->CalendarFolder) > 0)) {
            $ec = new EventCollectionObject(
                $cr->CalendarFolder[0]->FolderId->Id,
                $cr->CalendarFolder[0]->DisplayName,
                $cr->CalendarFolder[0]->FolderId->ChangeKey,
                $cr->CalendarFolder[0]->TotalCount
            );
            if (isset($cr->CalendarFolder[0]->ParentFolderId->Id)) {
                $ec->AffiliationId = $cr->CalendarFolder[0]->ParentFolderId->Id;
            }
            return $ec;
        } else {
            return null;
        }

    }

    /**
     * create collection in remote storage
     *
     * @param string $cid - Collection Item ID
     *
     * @return EventCollectionObject
     * @since Release 1.0.0
     *
     */
    public function createCollection(string $cid, string $name, bool $ctype = false): ?EventCollectionObject
    {

        // construct command object
        $ec = new CalendarFolderType();
        $ec->DisplayName = $name;
        // execute command
        $cr = $this->RemoteCommonService->createFolder($this->DataStore, $cid, $ec, $ctype);
        // process response
        if (isset($cr) && (count($cr->CalendarFolder) > 0)) {
            return new EventCollectionObject(
                $cr->CalendarFolder[0]->FolderId->Id,
                $name,
                $cr->CalendarFolder[0]->FolderId->ChangeKey
            );
        } else {
            return null;
        }

    }

    /**
     * delete collection in remote storage
     *
     * @param string $cid - Collection ID
     *
     * @return bool Ture - successfully destroyed / False - failed to destory
     * @since Release 1.0.0
     *
     */
    public function deleteCollection(string $cid): bool
    {

        // construct command object
        $ec = new FolderIdType($cid);
        // execute command
        $cr = $this->RemoteCommonService->deleteFolder($this->DataStore, array($ec));
        // process response
        if ($cr) {
            return true;
        } else {
            return false;
        }

    }

    /**
     * retrieve alteration for specific collection
     *
     * @param string $cid - Collection Id
     * @param string $state - Collection State (Initial/Last)
     *
     * @return object
     * @since Release 1.0.0
     *
     */
    public function fetchCollectionChanges(string $cid, string $state, string $scheme = 'I'): ?object
    {

        // construct additional properties required
        $properties = new NonEmptyArrayOfPathsToElementType();
        $properties->ExtendedFieldURI[] = new PathToExtendedFieldType(
            'PublicStrings',
            null,
            null,
            'DAV:uid',
            null,
            'String'
        );
        // execute command
        $cr = $this->RemoteCommonService->fetchFolderChanges($this->DataStore, $cid, $state, false, 512, $scheme, $properties);
        // return response
        return $cr;

    }

    /**
     * retrieve all collection items uuids from remote storage
     *
     * @param string $cid - Collection ID
     *
     * @return array
     * @since Release 1.0.0
     *
     */
    public function fetchCollectionItemsUUID(string $cid, bool $ctype = false): array
    {

        // construct properties required
        $properties = new NonEmptyArrayOfPathsToElementType();
        $properties->FieldURI[] = new PathToUnindexedFieldType('calendar:UID');
        $properties->ExtendedFieldURI[] = new PathToExtendedFieldType(
            'PublicStrings',
            null,
            null,
            'DAV:uid',
            null,
            'String'
        );
        // define place holders
        $data = array();
        $offset = 0;
        do {
            // execute command
            $ro = $this->RemoteCommonService->fetchItems($this->DataStore, $cid, $ctype, $offset, 512, 'I', $properties);
            // validate response object
            if (isset($ro) && count($ro->CalendarItem) > 0) {
                foreach ($ro->CalendarItem as $entry) {
                    // evaluate if standard properties UUID is present
                    if (!empty($entry->UID)) {
                        // extract and validate UUID from standard properties
                        $uuid = UUID::normalize($entry->UID);
                    }
                    // evaluate if valid uuid was not found and extended properties UUID is present
                    if (!empty($uuid) && !empty($entry->ExtendedProperty[0]->Value)) {
                        // extract and validate UUID from extended properties
                        $uuid = UUID::normalize($entry->ExtendedProperty[0]->Value);
                    }
                    // evaluate if valid uuid exists
                    if (!empty($uuid)) {
                        // add item id and uuid to id collection
                        $data[] = array('ID' => $entry->ItemId->Id, 'UUID' => $uuid);
                    }
                }
                // increment offset by count of returned items
                $offset += count($ro->CalendarItem);
            }
        } while (isset($ro) && count($ro->CalendarItem) > 0);
        // return id collection
        return $data;
    }

    /**
     * retrieve collection item in remote storage
     *
     * @param string $iid - Collection Item ID
     *
     * @return ?EventObject
     * @since Release 1.0.0
     *
     */
    public function fetchCollectionItem(string $iid): ?EventObject
    {

        // construct identification object
        $io = new ItemIdType($iid);
        // execute command
        $ro = $this->RemoteCommonService->fetchItem($this->DataStore, array($io), 'D', $this->constructDefaultItemProperties());
        // validate response
        if (isset($ro->CalendarItem)) {
            // convert to event object
            $eo = $this->toEventObject($ro->CalendarItem[0]);
            // retrieve attachment(s) from remote data store
            if (count($eo->Attachments) > 0) {
                $eo->Attachments = $this->fetchCollectionItemAttachment(array_column($eo->Attachments, 'Id'));
            }
            // return object
            return $eo;
        } else {
            return null;
        }

    }

    /**
     * find collection item by uuid in remote storage
     *
     * @param string $cid - Collection ID
     * @param string $uuid -Collection Item UUID
     *
     * @return EventObject
     * @since Release 1.0.0
     *
     */
    public function fetchCollectionItemByUUID(string $cid, string $uuid): ?EventObject
    {

        // retrieve properties for a specific collection item
        $ro = $this->RemoteCommonService->findItemByUUID($this->DataStore, $cid, $uuid, false, 'D', $this->constructDefaultItemProperties());
        // validate response
        if (isset($ro->CalendarItem)) {
            // convert to event object
            $eo = $this->toEventObject($ro->CalendarItem[0]);
            // retrieve attachment(s) from remote data store
            if (count($eo->Attachments) > 0) {
                $eo->Attachments = $this->fetchCollectionItemAttachment(array_column($eo->Attachments, 'Id'));
            }
            // return object
            return $eo;
        } else {
            return null;
        }

    }

    /**
     * create collection item in remote storage
     *
     * @param string $cid - Collection ID
     * @param EventObject $so - Source Data
     *
     * @return EventObject
     * @since Release 1.0.0
     *
     */
    public function createCollectionItem(string $cid, EventObject $so): ?EventObject
    {

        // construct request object
        $ro = new CalendarItemType();
        // UUID
        if (!empty($so->UUID)) {
            $ro->UID = UUID::convert($so->UUID, UUID::TYPE_MICROSOFT_HEX_SHORT);
            $ro->ExtendedProperty[] = $this->createFieldExtendedByName('PublicStrings', 'DAV:uid', 'String', $so->UUID);
        }
        // Start Date/Time
        if (!empty($so->StartsOn)) {
            // ews wants the date time in UTC
            // clone start date
            $dt = clone $so->StartsOn;
            // change timezone on cloned date
            $dt->setTimezone(new DateTimeZone('UTC'));
            // construct start time attribute
            $ro->Start = $dt->format('Y-m-d\\TH:i:s\Z');
            // evaluate if event starts time zone is present
            if ($so->StartsTZ instanceof \DateTimeZone) {
                $tz = $so->StartsTZ;
            } // evaluate if user default time zone is present
            elseif ($this->UserTimeZone instanceof \DateTimeZone) {
                $tz = $this->UserTimeZone;
            } // use system default time zone if no other option was present
            else {
                $tz = $this->SystemTimeZone;
            }
            // convert time zone
            $tz = $this->toTimeZone($tz);
            // construct time zone attribute
            if (!empty($tz)) {
                $ro->StartTimeZone = $this->constructTimeZone($tz);
            } else {
                $ro->StartTimeZone = $this->constructTimeZone('UTC');
            }
            unset($tz);
            unset($dt);
        }
        // End Date/Time
        if (!empty($so->EndsOn)) {
            // ews wants the date time in UTC
            // clone end date
            $dt = clone $so->EndsOn;
            // change timezone on cloned date
            $dt->setTimezone(new DateTimeZone('UTC'));
            // construct end time attribute
            $ro->End = $dt->format('Y-m-d\\TH:i:s\Z');
            // evaluate if event ends time zone is present
            if ($so->EndsTZ instanceof \DateTimeZone) {
                $tz = $so->EndsTZ;
            } // evaluate if user default time zone is present
            elseif ($this->UserTimeZone instanceof \DateTimeZone) {
                $tz = $this->UserTimeZone;
            } // use system default time zone if no other option was present
            else {
                $tz = $this->SystemTimeZone;
            }
            // convert time zone
            $tz = $this->toTimeZone($tz);
            // construct time zone attribute
            if (!empty($tz)) {
                $ro->EndTimeZone = $this->constructTimeZone($tz);
            } else {
                $ro->EndTimeZone = $this->constructTimeZone('UTC');
            }
            unset($tz);
            unset($dt);
        }
        // All Day Event
        if (!empty($so->Span) && $so->Span == 'F') {
            $ro->IsAllDayEvent = true;
        } else {
            $ro->IsAllDayEvent = false;
        }
        // TimeZone
        if ($so->TimeZone instanceof \DateTimeZone) {
            // convert time zone
            $tz = $this->toTimeZone($so->TimeZone);
            if (isset($tz)) {
                $ro->TimeZone = $this->constructTimeZone($tz);
            }
        }
        // Label
        if (!empty($so->Label)) {
            $ro->Subject = $so->Label;
        }
        // Notes
        if (!empty($so->Notes)) {
            $ro->Body = new BodyType(
                'Text',
                $so->Notes
            );
        }
        // Location
        if (!empty($so->Location)) {
            $ro->Location = $so->Location;
        }
        // Availability
        if (!empty($so->Availability)) {
            $ro->LegacyFreeBusyStatus = $so->Availability;
        }
        // Priority
        if (!empty($so->Priority)) {
            $ro->Importance = $this->toImportance($so->Priority);
        }
        // Sensitivity
        if (!empty($so->Sensitivity)) {
            $ro->Sensitivity = $this->toSensitivity($so->Sensitivity);
        }
        // Tag(s)
        if (count($so->Tags) > 0) {
            $ro->Categories = new ArrayOfStringsType;
            foreach ($so->Tags as $entry) {
                $ro->Categories->String[] = $entry;
            }
        }
        // Attendee(s)
        if (count($so->Attendee) > 0) {
            foreach ($so->Attendee as $entry) {
                // evaluate if email address is empty
                if (empty($entry->Address)) {
                    // skip attendee if address is empty
                    continue;
                }
                // evaluate if type is optional
                if ($entry->Type == 'O') {
                    if (!isset($ro->OptionalAttendees)) {
                        $ro->OptionalAttendees = new NonEmptyArrayOfAttendeesType;
                    }
                    $ro->OptionalAttendees->Attendee[] = new AttendeeType(
                        new EmailAddressType(
                            $entry->Address,
                            $entry->Name
                        ),
                        $this->toAttendeeResponse($entry->Attendance)
                    );
                } else {
                    if (!isset($ro->RequiredAttendees)) {
                        $ro->RequiredAttendees = new NonEmptyArrayOfAttendeesType;
                    }
                    $ro->RequiredAttendees->Attendee[] = new AttendeeType(
                        new EmailAddressType(
                            $entry->Address,
                            $entry->Name
                        ),
                        $this->toAttendeeResponse($entry->Attendance)
                    );
                }
            }
        }
        // Notifications
        if (count($so->Notifications) > 0) {
            if ($so->Notifications[0]->Type == 'D' && $so->Notifications[0]->Pattern == 'A') {
                $t = ceil((($so->StartsOn->getTimestamp() - $so->Notifications[0]->When->getTimestamp()) / 60));
                $ro->ReminderIsSet = true;
                $ro->ReminderMinutesBeforeStart = $t;
                unset($t);
            } elseif ($so->Notifications[0]->Type == 'D' && $so->Notifications[0]->Pattern == 'R') {
                $w = clone $so->Notifications[0]->When;
                $w->invert = 0;
                $t = ceil((new DateTime('@0'))->add($w)->getTimestamp() / 60);
                $ro->ReminderIsSet = true;
                $ro->ReminderMinutesBeforeStart = $t;
                unset($w, $t);
            }
        }
        // Occurrence
        if (isset($so->Occurrence) && !empty($so->Occurrence->Precision)) {

            $ro->Recurrence = new RecurrenceType();

            // Occurrence Iterations
            if (!empty($so->Occurrence->Iterations)) {
                $ro->Recurrence->NumberedRecurrence = new NumberedRecurrenceRangeType();
                $ro->Recurrence->NumberedRecurrence->NumberOfOccurrences = $so->Occurrence->Iterations;
                $ro->Recurrence->NumberedRecurrence->StartDate = $so->StartsOn->format('Y-m-d');
            }
            // Occurrence Conclusion
            if (!empty($so->Occurrence->Concludes)) {
                $ro->Recurrence->EndDateRecurrence = new EndDateRecurrenceRangeType();
                $ro->Recurrence->EndDateRecurrence->StartDate = $so->StartsOn->format('Y-m-d');
                if ($so->Origin == 'L') {
                    // subtract 1 day to adjust in how the end date is calculated in NC and EWS
                    $ro->Recurrence->EndDateRecurrence->EndDate = date_modify(clone $so->Occurrence->Concludes, '-1 day')->format('Y-m-d');
                } else {
                    $ro->Recurrence->EndDateRecurrence->EndDate = $so->Occurrence->Concludes->format('Y-m-d');
                }
            }
            // No Iterations And No Conclusion Date
            if (empty($so->Occurrence->Iterations) && empty($so->Occurrence->Concludes)) {
                $ro->Recurrence->NoEndRecurrence = new NoEndRecurrenceRangeType();
                $ro->Recurrence->NoEndRecurrence->StartDate = $so->StartsOn->format('Y-m-d');
            }

            // Based on Precision
            // Occurrence Daily
            if ($so->Occurrence->Precision == 'D') {
                $ro->Recurrence->DailyRecurrence = new DailyRecurrencePatternType();
                if (!empty($so->Occurrence->Interval)) {
                    $ro->Recurrence->DailyRecurrence->Interval = $so->Occurrence->Interval;
                } else {
                    $ro->Recurrence->DailyRecurrence->Interval = '1';
                }
            } // Occurrence Weekly
            elseif ($so->Occurrence->Precision == 'W') {
                $ro->Recurrence->WeeklyRecurrence = new WeeklyRecurrencePatternType();
                if (!empty($so->Occurrence->Interval)) {
                    $ro->Recurrence->WeeklyRecurrence->Interval = $so->Occurrence->Interval;
                } else {
                    $ro->Recurrence->WeeklyRecurrence->Interval = '1';
                }
                $ro->Recurrence->WeeklyRecurrence->DaysOfWeek = $this->toDaysOfWeek($so->Occurrence->OnDayOfWeek);
                $ro->Recurrence->WeeklyRecurrence->FirstDayOfWeek = 'Monday';
            } // Occurrence Monthly
            elseif ($so->Occurrence->Precision == 'M') {
                if ($so->Occurrence->Pattern == 'A') {
                    $ro->Recurrence->AbsoluteMonthlyRecurrence = new AbsoluteMonthlyRecurrencePatternType();
                    if (!empty($so->Occurrence->Interval)) {
                        $ro->Recurrence->AbsoluteMonthlyRecurrence->Interval = $so->Occurrence->Interval;
                    } else {
                        $ro->Recurrence->AbsoluteMonthlyRecurrence->Interval = '1';
                    }
                    $ro->Recurrence->AbsoluteMonthlyRecurrence->DayOfMonth = $this->toDaysOfMonth($so->Occurrence->OnDayOfMonth);
                } elseif ($so->Occurrence->Pattern == 'R') {
                    $ro->Recurrence->RelativeMonthlyRecurrence = new RelativeMonthlyRecurrencePatternType();
                    if (!empty($so->Occurrence->Interval)) {
                        $ro->Recurrence->RelativeMonthlyRecurrence->Interval = $so->Occurrence->Interval;
                    } else {
                        $ro->Recurrence->RelativeMonthlyRecurrence->Interval = '1';
                    }
                    if (count($so->Occurrence->OnDayOfWeek) > 0) {
                        $ro->Recurrence->RelativeMonthlyRecurrence->DaysOfWeek = $this->toDaysOfWeek($so->Occurrence->OnDayOfWeek, true);
                    }
                    if (count($so->Occurrence->OnWeekOfMonth) > 0) {
                        $ro->Recurrence->RelativeMonthlyRecurrence->DayOfWeekIndex = $this->toWeekOfMonth($so->Occurrence->OnWeekOfMonth);
                    }
                }


            } // Occurrence Yearly
            elseif ($so->Occurrence->Precision == 'Y') {
                if ($so->Occurrence->Pattern == 'A') {
                    $ro->Recurrence->AbsoluteYearlyRecurrence = new AbsoluteYearlyRecurrencePatternType();
                    // evaluate if occurance interval has a value
                    if (!empty($so->Occurrence->Interval)) {
                        $ro->Recurrence->AbsoluteYearlyRecurrence->Interval = $so->Occurrence->Interval;
                    } else {
                        $ro->Recurrence->AbsoluteYearlyRecurrence->Interval = '1';
                    }
                    $ro->Recurrence->AbsoluteYearlyRecurrence->Month = $this->toMonthOfYear($so->Occurrence->OnMonthOfYear);
                    // evaluate if day of month has a value
                    if (!empty($so->Occurrence->OnDayOfMonth)) {
                        $ro->Recurrence->AbsoluteYearlyRecurrence->DayOfMonth = $this->toDaysOfMonth($so->Occurrence->OnDayOfMonth);
                    } else {
                        $ro->Recurrence->AbsoluteYearlyRecurrence->DayOfMonth = $so->StartsOn->format('d');
                    }
                } elseif ($so->Occurrence->Pattern == 'R') {
                    $ro->Recurrence->RelativeYearlyRecurrence = new RelativeYearlyRecurrencePatternType();
                    if (!empty($so->Occurrence->Interval)) {
                        $ro->Recurrence->RelativeYearlyRecurrence->Interval = $so->Occurrence->Interval;
                    } else {
                        $ro->Recurrence->RelativeYearlyRecurrence->Interval = '1';
                    }
                    if (count($so->Occurrence->OnDayOfWeek) > 0) {
                        $ro->Recurrence->RelativeYearlyRecurrence->DaysOfWeek = $this->toDaysOfWeek($so->Occurrence->OnDayOfWeek, true);
                    }
                    if (count($so->Occurrence->OnWeekOfMonth) > 0) {
                        $ro->Recurrence->RelativeYearlyRecurrence->DayOfWeekIndex = $this->toWeekOfMonth($so->Occurrence->OnWeekOfMonth);
                    }
                    if (count($so->Occurrence->OnMonthOfYear) > 0) {
                        $ro->Recurrence->RelativeYearlyRecurrence->Month = $this->toMonthOfYear($so->Occurrence->OnMonthOfYear);
                    }
                }
            }
            // Occurrence Exclusions

            if (count($so->Occurrence->Excludes) > 0) {
                /* Issue #30
                $ro->DeletedOccurrences = new \OCA\EWS\Components\EWS\ArrayType\NonEmptyArrayOfDeletedOccurrencesType();
                foreach ($so->Occurrence->Excludes as $entry) {
                    // clone start date
                    $dt = clone $entry;
                    // change timezone on cloned date
                    $dt->setTimezone(new DateTimeZone('UTC'));
                    // construct start time property
                    $ro->DeletedOccurrence[] = new \OCA\EWS\Components\EWS\Type\DeletedOccurrenceInfoType(
                        $dt->format('Y-m-d\\TH:i:s\Z')
                    );
                    unset($dt);
                }
                */
            }

        }

        // execute command
        $rs = $this->RemoteCommonService->createItem($this->DataStore, $cid, $ro);
        // process response
        if ($rs->CalendarItem[0]) {
            $eo = clone $so;
            $eo->ID = $rs->CalendarItem[0]->ItemId->Id;
            $eo->CID = $cid;
            $eo->State = $rs->CalendarItem[0]->ItemId->ChangeKey;
            // deposit attachment(s)
            if (count($eo->Attachments) > 0) {
                // create attachments in remote data store
                $eo->Attachments = $this->createCollectionItemAttachment($eo->ID, $eo->Attachments);
                $eo->State = $eo->Attachments[0]->AffiliateState;
            }
            return $eo;
        } else {
            return null;
        }

    }

    /**
     * update collection item in remote storage
     *
     * @param string $cid - Collection ID
     * @param string $iid - Collection Item ID
     * @param string $istate - Collection Item State
     * @param EventObject $so - Source Data
     *
     * @return EventObject
     * @since Release 1.0.0
     *
     */
    public function updateCollectionItem(string $cid, string $iid, string $istate, EventObject $so): ?EventObject
    {

        // request modifications array
        $rm = array();
        // request deletions array
        $rd = array();
        // UUID
        if (!empty($so->UUID)) {
            $rm[] = $this->updateFieldUnindexed('calendar:UID', 'UID', $so->UUID);
            $rm[] = $this->updateFieldExtendedByName('PublicStrings', 'DAV:uid', 'String', $so->UUID);
        } else {
            $rd[] = $this->deleteFieldUnindexed('calendar:UID');
            $rd[] = $this->deleteFieldExtendedByName('PublicStrings', 'DAV:uid', 'String');
        }
        // Time Zone
        if ($so->TimeZone instanceof \DateTimeZone) {
            $tz = $this->toTimeZone($so->TimeZone);
            $rm[] = $this->updateFieldUnindexed('calendar:TimeZone', 'TimeZone', $tz);
            unset($tz);
        }
        // Starts On
        if (!empty($so->StartsOn)) {
            // clone start date
            $dt = clone $so->StartsOn;
            // change timezone on cloned date
            $dt->setTimezone(new DateTimeZone('UTC'));
            // construct start time attribute
            $rm[] = $this->updateFieldUnindexed('calendar:Start', 'Start', $dt->format('Y-m-d\\TH:i:s\Z'));
            // evaluate if event starts time zone is present
            if ($so->StartsTZ instanceof \DateTimeZone) {
                $tz = $so->StartsTZ;
            } // evaluate if user default time zone is present
            elseif ($this->UserTimeZone instanceof \DateTimeZone) {
                $tz = $this->UserTimeZone;
            } // use system default time zone if no other option was present
            else {
                $tz = $this->SystemTimeZone;
            }
            // convert time zone
            $tz = $this->toTimeZone($tz);
            // construct time zone attribute
            if (!empty($tz)) {
                $rm[] = $this->updateFieldUnindexed(
                    'calendar:StartTimeZone',
                    'StartTimeZone',
                    $this->constructTimeZone($tz)
                );
            } else {
                $rm[] = $this->updateFieldUnindexed(
                    'calendar:StartTimeZone',
                    'StartTimeZone',
                    $this->constructTimeZone('UTC')
                );
            }
            unset($tz);
            unset($dt);
        }
        // Ends On
        if (!empty($so->EndsOn)) {
            // clone end date
            $dt = clone $so->EndsOn;
            // change timezone on cloned date
            $dt->setTimezone(new DateTimeZone('UTC'));
            // construct end time property
            $rm[] = $this->updateFieldUnindexed('calendar:End', 'End', $dt->format('Y-m-d\\TH:i:s\Z'));
            // evaluate if event ends time zone is present
            if ($so->EndsTZ instanceof \DateTimeZone) {
                $tz = $so->EndsTZ;
            } // evaluate if user default time zone is present
            elseif ($this->UserTimeZone instanceof \DateTimeZone) {
                $tz = $this->UserTimeZone;
            } // use system default time zone if no other option was present
            else {
                $tz = $this->SystemTimeZone;
            }
            // construct start time zone
            $tz = $this->toTimeZone($tz);
            // construct time zone attribute
            if (!empty($tz)) {
                $rm[] = $this->updateFieldUnindexed(
                    'calendar:EndTimeZone',
                    'EndTimeZone',
                    $this->constructTimeZone($tz)
                );
            } else {
                $rm[] = $this->updateFieldUnindexed(
                    'calendar:EndTimeZone',
                    'EndTimeZone',
                    $this->constructTimeZone('UTC')
                );
            }
            unset($tz);
            unset($dt);
        }
        // All Day Event
        if (!empty($so->Span) && $so->Span == 'F') {
            $rm[] = $this->updateFieldUnindexed('calendar:IsAllDayEvent', 'IsAllDayEvent', true);
        } else {
            $rm[] = $this->updateFieldUnindexed('calendar:IsAllDayEvent', 'IsAllDayEvent', false);
        }
        // Label
        if (!empty($so->Label)) {
            $rm[] = $this->updateFieldUnindexed('item:Subject', 'Subject', $so->Label);
        } else {
            $rd[] = $this->deleteFieldUnindexed('item:Subject');
        }
        // Notes
        if (!empty($so->Notes)) {
            $rm[] = $this->updateFieldUnindexed(
                'item:Body',
                'Body',
                new BodyType(
                    'HTML',
                    $so->Notes
                ));
        } else {
            $rd[] = $this->deleteFieldUnindexed('item:Body');
        }
        // Location
        if (!empty($so->Location)) {
            $rm[] = $this->updateFieldUnindexed('calendar:Location', 'Location', $so->Location);
        } else {
            $rd[] = $this->deleteFieldUnindexed('calendar:Location');
        }
        // Availability
        if (!empty($so->Availability)) {
            $rm[] = $this->updateFieldUnindexed('calendar:LegacyFreeBusyStatus', 'LegacyFreeBusyStatus', $so->Availability);
        }
        // Priority
        if (!empty($so->Priority)) {
            $rm[] = $this->updateFieldUnindexed('item:Importance', 'Importance', $this->toImportance($so->Priority));
        }
        // Sensitivity
        if (!empty($so->Sensitivity)) {
            $rm[] = $this->updateFieldUnindexed('item:Sensitivity', 'Sensitivity', $this->toSensitivity($so->Sensitivity));
        }
        // Tag(s)
        if (count($so->Tags) > 0) {
            $t = new ArrayOfStringsType;
            foreach ($so->Tags as $entry) {
                $t->String[] = $entry;
            }
            $rm[] = $this->updateFieldUnindexed('item:Categories', 'Categories', $t);
            unset($t);
        } else {
            $rd[] = $this->deleteFieldUnindexed('item:Categories');
        }
        // Attendee(s)
        if (count($so->Attendee) > 0) {
            foreach ($so->Attendee as $entry) {
                // evaluate if email address is empty
                if (empty($entry->Address)) {
                    // skip attendee if address is empty
                    continue;
                }
                // evaluate if type is optional
                if ($entry->Type == 'O') {
                    if (!isset($oa)) {
                        $oa = new NonEmptyArrayOfAttendeesType;
                    }
                    $oa->Attendee[] = new AttendeeType(
                        new EmailAddressType(
                            $entry->Address,
                            $entry->Name
                        ),
                        $this->toAttendeeResponse($entry->Attendance)
                    );
                } else {
                    if (!isset($ra)) {
                        $ra = new NonEmptyArrayOfAttendeesType;
                    }
                    $ra->Attendee[] = new AttendeeType(
                        new EmailAddressType(
                            $entry->Address,
                            $entry->Name
                        ),
                        $this->toAttendeeResponse($entry->Attendance)
                    );
                }
            }
            if (isset($ra)) {
                $rm[] = $this->updateFieldUnindexed('calendar:RequiredAttendees', 'RequiredAttendees', $ra);
                unset($ra);
            } else {
                $rd[] = $this->deleteFieldUnindexed('calendar:RequiredAttendees');
            }
            if (isset($oa)) {
                $rm[] = $this->updateFieldUnindexed('calendar:OptionalAttendees', 'OptionalAttendees', $oa);
                unset($oa);
            } else {
                $rd[] = $this->deleteFieldUnindexed('calendar:OptionalAttendees');
            }
            unset($ra);
            unset($oa);
        } else {
            $rd[] = $this->deleteFieldUnindexed('calendar:RequiredAttendees');
            $rd[] = $this->deleteFieldUnindexed('calendar:OptionalAttendees');
        }
        // Notification(s)
        if (count($so->Notifications) > 0) {
            if ($so->Notifications[0]->Type == 'D' && $so->Notifications[0]->Pattern == 'A') {
                $t = ceil((($so->StartsOn->getTimestamp() - $so->Notifications[0]->When->getTimestamp()) / 60));
                $rm[] = $this->updateFieldUnindexed('item:ReminderMinutesBeforeStart', 'ReminderMinutesBeforeStart', $t);
                $rm[] = $this->updateFieldUnindexed('item:ReminderIsSet', 'ReminderIsSet', true);
                unset($t);
            } elseif ($so->Notifications[0]->Type == 'D' && $so->Notifications[0]->Pattern == 'R') {
                $w = clone $so->Notifications[0]->When;
                $w->invert = 0;
                $t = ceil((new DateTime('@0'))->add($w)->getTimestamp() / 60);
                $rm[] = $this->updateFieldUnindexed('item:ReminderMinutesBeforeStart', 'ReminderMinutesBeforeStart', $t);
                $rm[] = $this->updateFieldUnindexed('item:ReminderIsSet', 'ReminderIsSet', true);
                unset($w, $t);
            }
        } else {
            $rm[] = $this->updateFieldUnindexed('item:ReminderIsSet', 'ReminderIsSet', false);
            $rm[] = $this->updateFieldUnindexed('item:ReminderMinutesBeforeStart', 'ReminderMinutesBeforeStart', 0);
        }
        // Occurrence
        if (isset($so->Occurrence) && !empty($so->Occurrence->Precision)) {
            // construct recurrence object
            $f = new RecurrenceType();
            // Iterations
            if (!empty($so->Occurrence->Iterations)) {
                $f->NumberedRecurrence = new NumberedRecurrenceRangeType();
                $f->NumberedRecurrence->NumberOfOccurrences = $so->Occurrence->Iterations;
                $f->NumberedRecurrence->StartDate = $so->StartsOn->format('Y-m-d');
            }
            // Conclusion
            if (!empty($so->Occurrence->Concludes)) {
                $f->EndDateRecurrence = new EndDateRecurrenceRangeType();
                $f->EndDateRecurrence->StartDate = $so->StartsOn->format('Y-m-d');
                $f->EndDateRecurrence->EndDate = $so->Occurrence->Concludes->format('Y-m-d');
            }
            // No Iterations And No Conclusion Date
            if (empty($so->Occurrence->Iterations) && empty($so->Occurrence->Concludes)) {
                $f->NoEndRecurrence = new NoEndRecurrenceRangeType();
                $f->NoEndRecurrence->StartDate = $so->StartsOn->format('Y-m-d');
            }

            // Based on Precision
            // Daily Event
            if ($so->Occurrence->Precision == 'D') {
                $f->DailyRecurrence = new DailyRecurrencePatternType();
                if (!empty($so->Occurrence->Interval)) {
                    $f->DailyRecurrence->Interval = $so->Occurrence->Interval;
                } else {
                    $f->DailyRecurrence->Interval = '1';
                }
            } // Weekly Event
            elseif ($so->Occurrence->Precision == 'W') {
                $f->WeeklyRecurrence = new WeeklyRecurrencePatternType();
                if (!empty($so->Occurrence->Interval)) {
                    $f->WeeklyRecurrence->Interval = $so->Occurrence->Interval;
                } else {
                    $f->WeeklyRecurrence->Interval = '1';
                }
                $f->WeeklyRecurrence->DaysOfWeek = $this->toDaysOfWeek($so->Occurrence->OnDayOfWeek);
                $f->WeeklyRecurrence->FirstDayOfWeek = 'Monday';
            } // Monthly Event
            elseif ($so->Occurrence->Precision == 'M') {
                if ($so->Occurrence->Pattern == 'A') {
                    $f->AbsoluteMonthlyRecurrence = new AbsoluteMonthlyRecurrencePatternType();
                    if (!empty($so->Occurrence->Interval)) {
                        $f->AbsoluteMonthlyRecurrence->Interval = $so->Occurrence->Interval;
                    } else {
                        $f->AbsoluteMonthlyRecurrence->Interval = '1';
                    }
                    $f->AbsoluteMonthlyRecurrence->DayOfMonth = $this->toDaysOfMonth($so->Occurrence->OnDayOfMonth);
                } elseif ($so->Occurrence->Pattern == 'R') {
                    $f->RelativeMonthlyRecurrence = new RelativeMonthlyRecurrencePatternType();
                    if (!empty($so->Occurrence->Interval)) {
                        $f->RelativeMonthlyRecurrence->Interval = $so->Occurrence->Interval;
                    } else {
                        $f->RelativeMonthlyRecurrence->Interval = '1';
                    }
                    if (count($so->Occurrence->OnDayOfWeek) > 0) {
                        $f->RelativeMonthlyRecurrence->DaysOfWeek = $this->toDaysOfWeek($so->Occurrence->OnDayOfWeek, true);
                    }
                    if (count($so->Occurrence->OnWeekOfMonth) > 0) {
                        $f->RelativeMonthlyRecurrence->DayOfWeekIndex = $this->toWeekOfMonth($so->Occurrence->OnWeekOfMonth);
                    }
                }
            } // Yearly Event
            elseif ($so->Occurrence->Precision == 'Y') {
                if ($so->Occurrence->Pattern == 'A') {
                    $f->AbsoluteYearlyRecurrence = new AbsoluteYearlyRecurrencePatternType();
                    // evaluate if occurance interval has a value
                    if (!empty($so->Occurrence->Interval)) {
                        $f->AbsoluteYearlyRecurrence->Interval = $so->Occurrence->Interval;
                    } else {
                        $f->AbsoluteYearlyRecurrence->Interval = '1';
                    }
                    $f->AbsoluteYearlyRecurrence->Month = $this->toMonthOfYear($so->Occurrence->OnMonthOfYear);
                    // evaluate if day of month has a value
                    if (!empty($so->Occurrence->OnDayOfMonth)) {
                        $f->AbsoluteYearlyRecurrence->DayOfMonth = $this->toDaysOfMonth($so->Occurrence->OnDayOfMonth);
                    } else {
                        $f->AbsoluteYearlyRecurrence->DayOfMonth = $so->StartsOn->format('d');
                    }
                } elseif ($so->Occurrence->Pattern == 'R') {
                    $f->RelativeYearlyRecurrence = new RelativeYearlyRecurrencePatternType();
                    if (!empty($so->Occurrence->Interval)) {
                        $f->RelativeYearlyRecurrence->Interval = $so->Occurrence->Interval;
                    } else {
                        $f->RelativeYearlyRecurrence->Interval = '1';
                    }
                    if (count($so->Occurrence->OnDayOfWeek) > 0) {
                        $f->RelativeYearlyRecurrence->DaysOfWeek = $this->toDaysOfWeek($so->Occurrence->OnDayOfWeek, true);
                    }
                    if (count($so->Occurrence->OnWeekOfMonth) > 0) {
                        $f->RelativeYearlyRecurrence->DayOfWeekIndex = $this->toWeekOfMonth($so->Occurrence->OnWeekOfMonth);
                    }
                    if (count($so->Occurrence->OnMonthOfYear) > 0) {
                        $f->RelativeYearlyRecurrence->Month = $this->toMonthOfYear($so->Occurrence->OnMonthOfYear);
                    }
                }
            }

            $rm[] = $this->updateFieldUnindexed('calendar:Recurrence', 'Recurrence', $f);

            // Occurrence Exclusions
            if (count($so->Occurrence->Excludes) > 0) {
                /* Issue #30
                $f = new \OCA\EWS\Components\EWS\ArrayType\NonEmptyArrayOfDeletedOccurrencesType();
                foreach ($so->Occurrence->Excludes as $entry) {
                    // clone start date
                    $dt = clone $entry;
                    // change timezone on cloned date
                    $dt->setTimezone(new DateTimeZone('UTC'));
                    // construct start time property
                    $f->DeletedOccurrence[] = new \OCA\EWS\Components\EWS\Type\DeletedOccurrenceInfoType(
                        $dt->format('Y-m-d\\TH:i:s\Z')
                    );
                    unset($dt);
                }
                $rm[] = $this->updateFieldUnindexed('calendar:DeletedOccurrences', 'DeletedOccurrences', $f);
                */
            }
        } else {
            $rd[] = $this->deleteFieldUnindexed('calendar:Recurrence');
        }
        // execute command
        $rs = $this->RemoteCommonService->updateItem($this->DataStore, $cid, $iid, null, null, $rm, $rd);
        // process response
        if ($rs->CalendarItem[0]) {
            $eo = clone $so;
            $eo->ID = $rs->CalendarItem[0]->ItemId->Id;
            $eo->CID = $cid;
            $eo->State = $rs->CalendarItem[0]->ItemId->ChangeKey;
            // deposit attachment(s)
            if (count($eo->Attachments) > 0) {
                // create attachments in remote data store
                $eo->Attachments = $this->createCollectionItemAttachment($eo->ID, $eo->Attachments);
                $eo->State = $eo->Attachments[0]->AffiliateState;
            }
            return $eo;
        } else {
            return null;
        }

    }

    /**
     * update collection item with uuid in remote storage
     *
     * @param string $cid - Collection ID
     * @param string $iid - Collection Item ID
     * @param string $istate - Collection Item State
     * @param string $cid - Collection Item UUID
     *
     * @return object Status Object - item id, item uuid, item state token / Null - failed to create
     * @since Release 1.0.0
     *
     */
    public function updateCollectionItemUUID(string $cid, string $iid, string $istate, string $uuid): ?object
    {
        // request modifications array
        $rm = array();
        // construct update command object
        $rm[] = $this->updateFieldUnindexed('calendar:UID', 'UID', $uuid);
        $rm[] = $this->updateFieldExtendedByName('PublicStrings', 'DAV:uid', 'String', $uuid);
        // execute request
        $rs = $this->RemoteCommonService->updateItem($this->DataStore, $cid, $iid, null, null, $rm, null);
        // return response
        if ($rs->CalendarItem[0]) {
            return (object)array('ID' => $rs->CalendarItem[0]->ItemId->Id, 'UID' => $uuid, 'State' => $rs->CalendarItem[0]->ItemId->ChangeKey);
        } else {
            return null;
        }
    }

    /**
     * delete collection item in remote storage
     *
     * @param string $iid - Item ID
     *
     * @return bool Ture - successfully destroyed / False - failed to destory
     * @since Release 1.0.0
     *
     */
    public function deleteCollectionItem(string $iid): bool
    {
        // create object
        $o = new ItemIdType($iid);

        $result = $this->RemoteCommonService->deleteItem($this->DataStore, array($o), 'HardDelete');

        if ($result) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * retrieve collection item attachment from local storage
     *
     * @param string $aid - Attachment ID
     *
     * @return EventAttachmentObject
     * @since Release 1.0.0
     *
     */
    public function fetchCollectionItemAttachment(array $batch): array
    {

        // check to for entries in batch collection
        if (count($batch) == 0) {
            return array();
        }
        // retrieve attachments
        $rs = $this->RemoteCommonService->fetchAttachment($this->DataStore, $batch);
        // construct response collection place holder
        $rc = array();
        // check for response
        if (isset($rs)) {
            // process collection of objects
            foreach ($rs as $entry) {
                if (!isset($entry->ContentType) || $entry->ContentType == 'application/octet-stream') {
                    $type = MIME::fromFileName($entry->Name);
                } else {
                    $type = $entry->ContentType;
                }
                // insert attachment object in response collection
                $rc[] = new EventAttachmentObject(
                    'D',
                    $entry->AttachmentId->Id,
                    $entry->Name,
                    $type,
                    'B',
                    $entry->Size,
                    $entry->Content
                );
            }
        }
        // return response collection
        return $rc;

    }

    /**
     * create collection item attachment in local storage
     *
     * @param string $aid - Affiliation ID
     * @param array $sc - Collection of EventAttachmentObject(S)
     *
     * @return string
     * @since Release 1.0.0
     *
     */
    public function createCollectionItemAttachment(string $aid, array $batch): array
    {

        // check to for entries in batch collection
        if (count($batch) == 0) {
            return array();
        }
        // construct command collection place holder
        $cc = array();
        // process batch
        foreach ($batch as $key => $entry) {
            // construct command object
            $co = new FileAttachmentType();
            $co->IsInline = false;
            $co->IsContactPhoto = false;
            $co->Name = $entry->Name;
            $co->ContentId = $entry->Name;
            $co->ContentType = $entry->Type;
            $co->Size = $entry->Size;

            switch ($entry->Encoding) {
                case 'B':
                    $co->Content = $entry->Data;
                    break;
                case 'B64':
                    $co->Content = base64_decode($entry->Data);
                    break;
            }
            // insert command object in to collection
            $cc[] = $co;
        }
        // execute command(s)
        $rs = $this->RemoteCommonService->createAttachment($this->DataStore, $aid, $cc);
        // construct results collection place holder
        $rc = array();
        // check for response
        if (isset($rs)) {
            // process collection of objects
            foreach ($rs as $key => $entry) {
                $ro = clone $batch[$key];
                $ro->Id = $entry->AttachmentId->Id;
                $ro->Data = null;
                $ro->AffiliateId = $entry->AttachmentId->RootItemId;
                $ro->AffiliateState = $entry->AttachmentId->RootItemChangeKey;
                $rc[] = $ro;
            }

        }
        // return response collection
        return $rc;
    }

    /**
     * delete collection item attachment from local storage
     *
     * @param string $aid - Attachment ID
     *
     * @return bool true - successfully delete / False - failed to delete
     * @since Release 1.0.0
     *
     */
    public function deleteCollectionItemAttachment(array $batch): array
    {

        // check to for entries in batch collection
        if (count($batch) == 0) {
            return array();
        }
        // execute command
        $data = $this->RemoteCommonService->deleteAttachment($this->DataStore, $batch);

        return $data;

    }

    /**
     * construct collection of default remote collection properties
     *
     * @return object
     * @since Release 1.0.0
     *
     */
    public function constructDefaultCollectionProperties(): object
    {

        // evaluate if default collection properties collection exisits
        if (!isset($this->DefaultCollectionProperties)) {
            // unindexed property names collection
            $_properties = [
                'folder:FolderId',
                'folder:FolderClass',
                'folder:ParentFolderId',
                'folder:DisplayName',
                'folder:TotalCount',
            ];
            // construct property collection
            $this->DefaultCollectionProperties = new NonEmptyArrayOfPathsToElementType();
            foreach ($_properties as $entry) {
                $this->DefaultCollectionProperties->FieldURI[] = new PathToUnindexedFieldType($entry);
            }
        }

        return $this->DefaultCollectionProperties;

    }

    /**
     * construct collection of default remote object properties
     *
     * @return object
     * @since Release 1.0.0
     *
     */
    public function constructDefaultItemProperties(): object
    {

        // evaluate if default item properties collection exisits
        if (!isset($this->DefaultItemProperties)) {
            // unindexed property names collection
            $_properties = [
                'item:ItemId',
                'calendar:UID',
                'item:ParentFolderId',
                'item:DateTimeCreated',
                'item:DateTimeSent',
                'item:LastModifiedTime',
                'calendar:TimeZone',
                'calendar:Start',
                'calendar:StartTimeZone',
                'calendar:End',
                'calendar:EndTimeZone',
                'item:Subject',
                'item:Body',
                'calendar:Location',
                'calendar:LegacyFreeBusyStatus',
                'item:Importance',
                'item:Sensitivity',
                'item:Categories',
                'calendar:Organizer',
                'calendar:RequiredAttendees',
                'calendar:OptionalAttendees',
                'item:ReminderIsSet',
                'item:ReminderMinutesBeforeStart',
                'calendar:Recurrence',
                'calendar:ModifiedOccurrences',
                'calendar:DeletedOccurrences',
                'item:Attachments',
                'calendar:IsAllDayEvent',

                'item:UniqueBody',
                'calendar:AppointmentState',
                'calendar:Resources',
            ];
            // construct property collection
            $this->DefaultItemProperties = new NonEmptyArrayOfPathsToElementType();
            foreach ($_properties as $entry) {
                $this->DefaultItemProperties->FieldURI[] = new PathToUnindexedFieldType($entry);
            }

            // construct extended property collection
            $this->DefaultItemProperties->ExtendedFieldURI[] = new PathToExtendedFieldType(
                'PublicStrings',
                null,
                null,
                'DAV:id',
                null,
                'String'
            );
            $this->DefaultItemProperties->ExtendedFieldURI[] = new PathToExtendedFieldType(
                'PublicStrings',
                null,
                null,
                'DAV:uid',
                null,
                'String'
            );
        }

        return $this->DefaultItemProperties;

    }

    /**
     * construct collection item unindexed property update command
     *
     * @param string $uri - property uri
     * @param string $name - property name
     * @param string $value - property value
     *
     * @return object collection item property update command
     * @since Release 1.0.0
     *
     */
    public function updateFieldUnindexed(string $uri, string $name, mixed $value): object
    {
        // create field update object
        $o = new SetItemFieldType();
        $o->FieldURI = new PathToUnindexedFieldType($uri);
        // create field contact object
        $o->CalendarItem = new CalendarItemType();
        $o->CalendarItem->$name = $value;
        // return object
        return $o;
    }

    /**
     * construct collection item unindexed property delete command
     *
     * @param string $uri - property uri
     *
     * @return object collection item property delete command
     * @since Release 1.0.0
     *
     */
    public function deleteFieldUnindexed(string $uri): object
    {
        // create field delete object
        $o = new DeleteItemFieldType();
        $o->FieldURI = new PathToUnindexedFieldType($uri);
        // return object
        return $o;
    }

    /**
     * construct collection item indexed property update command
     *
     * @param string $uri - property uri
     * @param string $index - property index
     * @param string $name - property name
     * @param string $dictionary - property dictionary object
     * @param string $entry - property entry object
     *
     * @return object collection item property update command
     * @since Release 1.0.0
     *
     */
    public function updateFieldIndexed(string $uri, string $index, string $name, mixed $dictionary, mixed $entry): object
    {
        // create field update object
        $o = new SetItemFieldType();
        $o->IndexedFieldURI = new PathToIndexedFieldType($uri, $index);
        // create field contact object
        $o->CalendarItem = new CalendarItemType();
        $o->CalendarItem->$name = $dictionary;
        $o->CalendarItem->$name->Entry = $entry;
        // return object
        return $o;
    }

    /**
     * construct collection item indexed property delete command
     *
     * @param string $tag - property tag
     * @param string $type - property type
     * @param string $value - property value
     *
     * @return object collection item property delete command
     * @since Release 1.0.0
     *
     */
    public function deleteFieldIndexed(string $uri, string $index): object
    {
        // create field delete object
        $o = new DeleteItemFieldType();
        $o->IndexedFieldURI = new PathToIndexedFieldType($uri, $index);
        // return object
        return $o;
    }

    /**
     * construct collection item extended property create command
     *
     * @param string $collection - property collection
     * @param string $name - property name
     * @param string $type - property type
     * @param string $value - property value
     *
     * @return object collection item property create command
     *
     * @since Release 1.0.0
     *
     * @psalm-pure
     */
    public function createFieldExtendedByName(string $collection, string $name, string $type, mixed $value): object
    {
        // create extended field object
        $o = new ExtendedPropertyType(
            new PathToExtendedFieldType(
                $collection,
                null,
                null,
                $name,
                null,
                $type
            ),
            $value
        );
        // return object
        return $o;
    }

    /**
     * construct collection item extended property update command
     *
     * @param string $collection - property collection
     * @param string $name - property name
     * @param string $type - property type
     * @param string $value - property value
     *
     * @return object collection item property update command
     * @since Release 1.0.0
     *
     */
    public function updateFieldExtendedByName(string $collection, string $name, string $type, mixed $value): object
    {
        // create field update object
        $o = new SetItemFieldType();
        $o->ExtendedFieldURI = new PathToExtendedFieldType(
            $collection,
            null,
            null,
            $name,
            null,
            $type
        );
        // create field contact object
        $o->CalendarItem = new CalendarItemType();
        $o->CalendarItem->ExtendedProperty = new ExtendedPropertyType(
            new PathToExtendedFieldType(
                $collection,
                null,
                null,
                $name,
                null,
                $type
            ),
            $value
        );
        // return object
        return $o;
    }

    /**
     * construct collection item extended property delete command
     *
     * @param string $collection - property collection
     * @param string $name - property name
     * @param string $type - property type
     *
     * @return object collection item property delete command
     * @since Release 1.0.0
     *
     */
    public function deleteFieldExtendedByName(string $collection, string $name, string $type): object
    {
        // create field delete object
        $o = new DeleteItemFieldType();
        $o->ExtendedFieldURI = new PathToExtendedFieldType(
            $collection,
            null,
            null,
            $name,
            null,
            $type
        );
        // return object
        return $o;
    }

    /**
     * construct collection item extended property create command
     *
     * @param string $tag - property tag
     * @param string $type - property type
     * @param string $value - property value
     *
     * @return object collection item property create command
     *
     * @since Release 1.0.0
     *
     * @psalm-pure
     */
    public function createFieldExtendedByTag(string $tag, string $type, mixed $value): object
    {
        // create extended field object
        $o = new ExtendedPropertyType(
            new PathToExtendedFieldType(
                null,
                null,
                null,
                null,
                $tag,
                $type
            ),
            $value
        );
        // return object
        return $o;
    }

    /**
     * construct collection item extended property update command
     *
     * @param string $tag - property tag
     * @param string $type - property type
     * @param string $value - property value
     *
     * @return object collection item property update command
     * @since Release 1.0.0
     *
     */
    public function updateFieldExtendedByTag(string $tag, string $type, mixed $value): object
    {
        // create field update object
        $o = new SetItemFieldType();
        $o->ExtendedFieldURI = new PathToExtendedFieldType(
            null,
            null,
            null,
            null,
            $tag,
            $type
        );
        // create field contact object
        $o->CalendarItem = new CalendarItemType();
        $o->CalendarItem->ExtendedProperty = new ExtendedPropertyType(
            new PathToExtendedFieldType(
                null,
                null,
                null,
                null,
                $tag,
                $type
            ),
            $value
        );
        // return object
        return $o;
    }

    /**
     * construct collection item extended property delete command
     *
     * @param string $tag - property tag
     * @param string $type - property type
     *
     * @return object collection item property delete command
     * @since Release 1.0.0
     *
     */
    public function deleteFieldExtendedByTag(string $tag, string $type): object
    {
        // construct field delete object
        $o = new DeleteItemFieldType();
        $o->ExtendedFieldURI = new PathToExtendedFieldType(
            null,
            null,
            null,
            null,
            $tag,
            $type
        );
        // return object
        return $o;
    }

    /**
     * construct collection item time zone property
     *
     * @param string $tag - time zone name
     *
     * @return object collection item time zone property
     * @since Release 1.0.0
     *
     */
    public function constructTimeZone(string $name): object
    {
        // retrive time zone properties
        $zone = TimeZoneEWS::find($name);

        // construct time zone object
        $o = new TimeZoneDefinitionType;
        $o->Id = $zone->Id;
        $o->Name = $zone->Name;

        // Periods
        $o->Periods = new NonEmptyArrayOfPeriodsType();
        if (isset($zone->Periods->Period) && count($zone->Periods->Period) > 0) {
            foreach ($zone->Periods->Period as $entry) {
                $o->Periods->Period[] = new PeriodType(
                    $entry->Id,
                    $entry->Name,
                    $entry->Bias
                );
            }
        }

        // Transitions
        $o->Transitions = new ArrayOfTransitionsType();
        // Transition
        if (isset($zone->Transitions->Transition) && count($zone->Transitions->Transition) > 0) {
            foreach ($zone->Transitions->Transition as $entry) {
                $o->Transitions->Transition[] = new TransitionType(
                    new TransitionTargetType(
                        $entry->To->Kind,
                        $entry->To->_
                    )
                );
            }
        }
        // Absolute Date Transition
        /*
        if (isset($zone->Transitions->AbsoluteDateTransition) && count($zone->Transitions->AbsoluteDateTransition) > 0) {
            foreach ($zone->Transitions->AbsoluteDateTransition as $entry) {
                $o->Transitions->AbsoluteDateTransition[] = new \OCA\EWS\Components\EWS\Type\AbsoluteDateTransitionType(
                    new \OCA\EWS\Components\EWS\Type\TransitionTargetType(
                        $entry->To->Kind,
                        $entry->To->_
                    ),
                    $entry->DateTime
                );
            }
        }
        */

        // Recurring Date Transition
        if (isset($zone->Transitions->RecurringDateTransition) && count($zone->Transitions->RecurringDateTransition) > 0) {
            foreach ($zone->Transitions->RecurringDateTransition as $entry) {
                $o->Transitions->RecurringDateTransition[] = new RecurringDateTransitionType(
                    new TransitionTargetType(
                        $entry->To->Kind,
                        $entry->To->_
                    ),
                    $entry->TimeOffset,
                    $entry->Month,
                    $entry->Day
                );
            }
        }
        // Recurring Day Transition
        if (isset($zone->Transitions->RecurringDayTransition) && count($zone->Transitions->RecurringDayTransition) > 0) {
            foreach ($zone->Transitions->RecurringDayTransition as $entry) {
                $o->Transitions->RecurringDayTransition[] = new RecurringDayTransitionType(
                    new TransitionTargetType(
                        $entry->To->Kind,
                        $entry->To->_
                    ),
                    $entry->TimeOffset,
                    $entry->Month,
                    $entry->DayOfWeek,
                    $entry->Occurrence
                );
            }
        }

        // Transitions Groups
        if (isset($zone->TransitionsGroups->TransitionsGroup) && count($zone->TransitionsGroups->TransitionsGroup) > 0) {
            $o->TransitionsGroups = new ArrayOfTransitionsGroupsType();
            foreach ($zone->TransitionsGroups->TransitionsGroup as $key => $group) {
                $o->TransitionsGroups->TransitionsGroup[$key] = new ArrayOfTransitionsType();
                $o->TransitionsGroups->TransitionsGroup[$key]->Id = $group->Id;
                // Transition
                if (isset($group->Transition) && count($group->Transition) > 0) {
                    foreach ($group->Transition as $entry) {
                        $o->TransitionsGroups->TransitionsGroup[$key]->Transition[] = new TransitionType(
                            new TransitionTargetType(
                                $entry->To->Kind,
                                $entry->To->_
                            )
                        );
                    }
                }
                // Absolute Date Transition
                if (isset($group->AbsoluteDateTransition) && count($group->AbsoluteDateTransition) > 0) {
                    foreach ($group->AbsoluteDateTransition as $entry) {
                        $o->TransitionsGroups->TransitionsGroup[$key]->AbsoluteDateTransition[] = new AbsoluteDateTransitionType(
                            new TransitionTargetType(
                                $entry->To->Kind,
                                $entry->To->_
                            ),
                            $entry->DateTime
                        );
                    }
                }
                // Recurring Date Transition
                if (isset($group->RecurringDateTransition) && count($group->RecurringDateTransition) > 0) {
                    foreach ($group->RecurringDateTransition as $entry) {
                        $o->TransitionsGroups->TransitionsGroup[$key]->RecurringDateTransition[] = new RecurringDateTransitionType(
                            new TransitionTargetType(
                                $entry->To->Kind,
                                $entry->To->_
                            ),
                            $entry->TimeOffset,
                            $entry->Month,
                            $entry->Day
                        );
                    }
                }
                // Recurring Day Transition
                if (isset($group->RecurringDayTransition) && count($group->RecurringDayTransition) > 0) {
                    foreach ($group->RecurringDayTransition as $entry) {
                        $o->TransitionsGroups->TransitionsGroup[$key]->RecurringDayTransition[] = new RecurringDayTransitionType(
                            new TransitionTargetType(
                                $entry->To->Kind,
                                $entry->To->_
                            ),
                            $entry->TimeOffset,
                            $entry->Month,
                            $entry->DayOfWeek,
                            $entry->Occurrence
                        );
                    }
                }
            }
        }
        // return time zone definition object
        return $o;
    }

    /**
     * convert remote CalendarItemType object to EventObject
     *
     * @param CalendarItemType $data - item as CalendarItemType object
     *
     * @return EventObject item as EventObject
     * @since Release 1.0.0
     *
     */
    public function toEventObject(CalendarItemType $data): EventObject
    {

        // create object
        $o = new EventObject();
        // Origin
        $o->Origin = 'R';
        // ID / State
        if (isset($data->ItemId)) {
            $o->ID = $data->ItemId->Id;
            $o->State = $data->ItemId->ChangeKey;
        }
        // UUID
        if (!empty($data->UID)) {
            $o->UUID = UUID::normalize($data->UID);
        }
        // Collection ID
        if (isset($data->ParentFolderId)) {
            $o->CID = $data->ParentFolderId->Id;
        }
        // Creation Date
        if (!empty($data->DateTimeCreated)) {
            $o->CreatedOn = new DateTime($data->DateTimeCreated);
        }
        // Modification Date
        if (!empty($data->DateTimeSent)) {
            $o->ModifiedOn = new DateTime($data->DateTimeSent);
        }
        if (!empty($data->LastModifiedTime)) {
            $o->ModifiedOn = new DateTime($data->LastModifiedTime);
        }
        // Start Time Zone
        if (!empty($data->StartTimeZone)) {
            $o->StartsTZ = $this->fromTimeZone($data->StartTimeZone->Id);
        }
        // End Time Zone
        if (!empty($data->EndTimeZone)) {
            $o->EndsTZ = $this->fromTimeZone($data->EndTimeZone->Id);
        }
        // Time Zone
        if (!empty($data->TimeZone)) {
            $o->TimeZone = $this->fromTimeZone($data->TimeZone);
            if (isset($o->TimeZone)) {
                if (!isset($o->StartsTZ)) {
                    $o->StartsTZ = clone $o->TimeZone;
                }
                if (!isset($o->EndsTZ)) {
                    $o->EndsTZ = clone $o->TimeZone;
                }
            }
        }
        // Start Date/Time
        if (!empty($data->Start)) {
            $o->StartsOn = new DateTime($data->Start);
            //if (isset($o->StartsTZ)) { $o->StartsOn->setTimezone($o->StartsTZ); }
        }
        // End Date/Time
        if (!empty($data->End)) {
            $o->EndsOn = new DateTime($data->End);
            //if (isset($o->EndsTZ)) { $o->EndsOn->setTimezone($o->EndsTZ); }
        }
        // All Day Event
        if (isset($data->IsAllDayEvent) && $data->IsAllDayEvent == true) {
            $o->Span = 'F'; // Full
            //$o->StartsOn->setTime(0,0,0,0);
            //$o->EndsOn->setTime(0,0,0,0);
        } else {
            $o->Span = 'P'; // Partial
        }
        // Label
        if (!empty($data->Subject)) {
            $o->Label = $data->Subject;
        }
        // Notes
        if (!empty($data->Body)) {
            $o->Notes = $data->Body->_;
        }
        // Location
        if (!empty($data->Location)) {
            $o->Location = $data->Location;
        }
        // Availability
        if (!empty($data->LegacyFreeBusyStatus)) {
            $o->Availability = $data->LegacyFreeBusyStatus;
        }
        // Priority
        if (!empty($data->Importance)) {
            $o->Priority = $this->fromImportance($data->Importance);
        }
        // Sensitivity
        if (!empty($data->Sensitivity)) {
            $o->Sensitivity = $this->fromSensitivity($data->Sensitivity);
        }
        // Tag(s)
        if (isset($data->Categories)) {
            foreach ($data->Categories->String as $entry) {
                $o->addTag($entry);
            }
        }
        // Organizer
        if (isset($data->Organizer)) {
            $o->Organizer->Address = $data->Organizer->Mailbox->EmailAddress;
            $o->Organizer->Name = $data->Organizer->Mailbox->Name;
        }
        // Attendee(s)
        if (isset($data->RequiredAttendees)) {
            foreach ($data->RequiredAttendees->Attendee as $entry) {
                if ($entry->Mailbox->EmailAddress) {
                    $o->addAttendee(
                        $entry->Mailbox->EmailAddress,
                        $entry->Mailbox->Name,
                        'R',
                        $this->fromAttendeeResponse($entry->ResponseType));
                }
            }
        }
        if (isset($data->OptionalAttendees)) {
            foreach ($data->OptionalAttendees->Attendee as $entry) {
                if ($entry->Mailbox->EmailAddress) {
                    $o->addAttendee(
                        $entry->Mailbox->EmailAddress,
                        $entry->Mailbox->Name,
                        'O',
                        $this->fromAttendeeResponse($entry->ResponseType));
                }
            }
        }
        // Notification(s)
        if (isset($data->ReminderIsSet) && isset($data->ReminderMinutesBeforeStart)) {
            $w = new DateInterval('PT' . $data->ReminderMinutesBeforeStart . 'M');
            $w->invert = 1;
            $o->addNotification(
                'D',
                'R',
                $w
            );
        }
        // Occurrence
        if (isset($data->Recurrence)) {
            // Iterations
            if (isset($data->Recurrence->NumberedRecurrence->NumberOfOccurrences)) {
                $o->Occurrence->Iterations = $data->Recurrence->NumberedRecurrence->NumberOfOccurrences;
            }
            // Conclusion
            if (isset($data->Recurrence->EndDateRecurrence->EndDate)) {
                $o->Occurrence->Concludes = new DateTime($data->Recurrence->EndDateRecurrence->EndDate);
            }
            // Daily
            if (isset($data->Recurrence->DailyRecurrence)) {

                $o->Occurrence->Pattern = 'A';
                $o->Occurrence->Precision = 'D';

                if (isset($data->Recurrence->DailyRecurrence->Interval)) {
                    $o->Occurrence->Interval = $data->Recurrence->DailyRecurrence->Interval;
                }
                if (isset($data->Recurrence->DailyRecurrence->NumberOfOccurrences)) {
                    $o->Occurrence->Iterations = $data->Recurrence->DailyRecurrence->NumberOfOccurrences;
                }
            }
            // Weekly
            if (isset($data->Recurrence->WeeklyRecurrence)) {

                $o->Occurrence->Pattern = 'A';
                $o->Occurrence->Precision = 'W';

                if (isset($data->Recurrence->WeeklyRecurrence->Interval)) {
                    $o->Occurrence->Interval = $data->Recurrence->WeeklyRecurrence->Interval;
                }
                if (isset($data->Recurrence->WeeklyRecurrence->NumberOfOccurrences)) {
                    $o->Occurrence->Iterations = $data->Recurrence->WeeklyRecurrence->NumberOfOccurrences;
                }
                if (isset($data->Recurrence->WeeklyRecurrence->DaysOfWeek)) {
                    $o->Occurrence->OnDayOfWeek = $this->fromDaysOfWeek($data->Recurrence->WeeklyRecurrence->DaysOfWeek);
                }
            }
            // Monthly Absolute
            if (isset($data->Recurrence->AbsoluteMonthlyRecurrence)) {

                $o->Occurrence->Pattern = 'A';
                $o->Occurrence->Precision = 'M';

                if (isset($data->Recurrence->AbsoluteMonthlyRecurrence->Interval)) {
                    $o->Occurrence->Interval = $data->Recurrence->AbsoluteMonthlyRecurrence->Interval;
                }
                if (isset($data->Recurrence->AbsoluteMonthlyRecurrence->NumberOfOccurrences)) {
                    $o->Occurrence->Iterations = $data->Recurrence->AbsoluteMonthlyRecurrence->NumberOfOccurrences;
                }
                if (isset($data->Recurrence->AbsoluteMonthlyRecurrence->DayOfMonth)) {
                    $o->Occurrence->OnDayOfMonth = $this->fromDaysOfMonth($data->Recurrence->AbsoluteMonthlyRecurrence->DayOfMonth);
                }
            }
            // Monthly Relative
            if (isset($data->Recurrence->RelativeMonthlyRecurrence)) {

                $o->Occurrence->Pattern = 'R';
                $o->Occurrence->Precision = 'M';

                if (isset($data->Recurrence->RelativeMonthlyRecurrence->Interval)) {
                    $o->Occurrence->Interval = $data->Recurrence->RelativeMonthlyRecurrence->Interval;
                }
                if (isset($data->Recurrence->RelativeMonthlyRecurrence->DaysOfWeek)) {
                    $o->Occurrence->OnDayOfWeek = $this->fromDaysOfWeek($data->Recurrence->RelativeMonthlyRecurrence->DaysOfWeek, true);
                }
                if (isset($data->Recurrence->RelativeMonthlyRecurrence->DayOfWeekIndex)) {
                    $o->Occurrence->OnWeekOfMonth = $this->fromWeekOfMonth($data->Recurrence->RelativeMonthlyRecurrence->DayOfWeekIndex);
                }
            }
            // Yearly Absolute
            if (isset($data->Recurrence->AbsoluteYearlyRecurrence)) {

                $o->Occurrence->Pattern = 'A';
                $o->Occurrence->Precision = 'Y';

                if (isset($data->Recurrence->AbsoluteYearlyRecurrence->Interval)) {
                    $o->Occurrence->Interval = $data->Recurrence->AbsoluteYearlyRecurrence->Interval;
                }
                if (isset($data->Recurrence->AbsoluteYearlyRecurrence->NumberOfOccurrences)) {
                    $o->Occurrence->ExpiresCount = $data->Recurrence->AbsoluteYearlyRecurrence->NumberOfOccurrences;
                }
                if (isset($data->Recurrence->AbsoluteYearlyRecurrence->Month)) {
                    $o->Occurrence->OnMonthOfYear = $this->fromMonthOfYear($data->Recurrence->AbsoluteYearlyRecurrence->Month);
                }
                if (isset($data->Recurrence->AbsoluteYearlyRecurrence->DayOfMonth)) {
                    $o->Occurrence->OnDayOfMonth = $this->fromDaysOfMonth($data->Recurrence->AbsoluteYearlyRecurrence->DayOfMonth);
                }
            }
            // Yearly Relative
            if (isset($data->Recurrence->RelativeYearlyRecurrence)) {

                $o->Occurrence->Pattern = 'R';
                $o->Occurrence->Precision = 'Y';

                if (isset($data->Recurrence->RelativeYearlyRecurrence->DaysOfWeek)) {
                    $o->Occurrence->OnDayOfWeek = $this->fromDaysOfWeek($data->Recurrence->RelativeYearlyRecurrence->DaysOfWeek, true);
                }
                if (isset($data->Recurrence->RelativeYearlyRecurrence->DayOfWeekIndex)) {
                    $o->Occurrence->OnWeekOfMonth = $this->fromWeekOfMonth($data->Recurrence->RelativeYearlyRecurrence->DayOfWeekIndex);
                }
                if (isset($data->Recurrence->RelativeYearlyRecurrence->Month)) {
                    $o->Occurrence->OnMonthOfYear = $this->fromMonthOfYear($data->Recurrence->RelativeYearlyRecurrence->Month);
                }
            }
            // Excludes
            if (isset($data->DeletedOccurrences)) {
                foreach ($data->DeletedOccurrences->DeletedOccurrence as $entry) {
                    if (isset($entry->Start)) {
                        $o->Occurrence->Excludes[] = new DateTime($entry->Start);
                    }
                }
            }
        }
        // Attachment(s)
        if (isset($data->Attachments) && is_array($data->Attachments)) {
            foreach ($data->Attachments->FileAttachment as $entry) {
                if ($entry->ContentType == 'application/octet-stream') {
                    $type = MIME::fromFileName($entry->Name);
                } else {
                    $type = $entry->ContentType;
                }
                $o->addAttachment(
                    'D',
                    $entry->AttachmentId->Id,
                    $entry->Name,
                    $type,
                    'B',
                    $entry->Size
                );
            }
        }
        // Extended Properties
        if (isset($data->ExtendedProperty)) {
            foreach ($data->ExtendedProperty as $entry) {
                switch ($entry->ExtendedFieldURI->PropertyName) {
                    case 'DAV:uid':
                        //$o->UUID = $entry->Value;
                        break;
                }
                switch ($entry->ExtendedFieldURI->PropertyTag) {
                    case '0x3007':
                        $o->CreatedOn = new DateTime($entry->Value);
                        break;
                    case '0x3008':
                        $o->ModifiedOn = new DateTime($entry->Value);
                        break;
                }
            }
        }

        return $o;

    }

    /**
     * Converts EWS (Microsoft/Windows) time zone name to DateTimeZone object
     *
     * @param string $zone ews time zone name
     *
     * @return DateTimeZone valid DateTimeZone object on success, or null on failure
     *
     * @since Release 1.0.0
     *
     * @psalm-external-mutation-free
     */
    public function fromTimeZone(string $name): ?DateTimeZone
    {

        // convert EWS time zone name to DateTimeZone object
        return TimeZoneEWS::toDateTimeZone($name);

    }

    /**
     * Converts DateTimeZone object to EWS (Microsoft/Windows) time zone name
     *
     * @param DateTimeZone $zone
     *
     * @return string valid EWS time zone name on success, or null on failure
     *
     * @since Release 1.0.0
     *
     * @psalm-external-mutation-free
     */
    public function toTimeZone(DateTimeZone $zone): ?string
    {

        // convert DateTimeZone object to EWS time zone name
        return TimeZoneEWS::fromDateTimeZone($zone);

    }

    /**
     * convert remote days of the week to event object days of the week
     *
     * @param string $days - remote days of the week values(s)
     * @param bool $group - flag to check if days are grouped
     *
     * @return array event object days of the week values(s)
     *
     * @since Release 1.0.0
     *
     * @psalm-pure
     */
    public function fromDaysOfWeek(string $days, bool $group = false): array
    {

        // days conversion reference
        $_tm = array(
            'Monday' => 1,
            'Tuesday' => 2,
            'Wednesday' => 3,
            'Thursday' => 4,
            'Friday' => 5,
            'Saturday' => 6,
            'Sunday' => 7,
            'Day' => 0,
            'Weekday' => 8,
            'WeekendDay' => 9
        );
        // convert days to array
        $days = explode(' ', $days);
        // evaluate if days match any group patterns
        if ($group && count($days) == 1) {
            $groups = array(
                'Day' => array(1, 2, 3, 4, 5, 6, 7),
                'Weekday' => array(1, 2, 3, 4, 5),
                'WeekendDay' => array(6, 7)
            );
            if (isset($groups[$days[0]])) {
                return $groups[$days[0]];
            }
        }
        // convert day values
        foreach ($days as $key => $entry) {
            if (isset($_tm[$entry])) {
                $days[$key] = $_tm[$entry];
            }
        }
        // return converted days
        return $days;

    }

    /**
     * convert event object days of the week to remote days of the week
     *
     * @param array $days - event object days of the week values(s)
     * @param bool $group - flag to check if days can be grouped
     *
     * @return string remote days of the week values(s)
     *
     * @since Release 1.0.0
     *
     * @psalm-pure
     */
    public function toDaysOfWeek(array $days, bool $group = false): string
    {

        // days conversion reference
        $_tm = array(
            1 => 'Monday',
            2 => 'Tuesday',
            3 => 'Wednesday',
            4 => 'Thursday',
            5 => 'Friday',
            6 => 'Saturday',
            7 => 'Sunday',
            0 => 'Day',
            8 => 'Weekday',
            9 => 'WeekendDay'
        );
        // evaluate if days match any group patterns
        if ($group) {
            $groups = array(
                'Day' => array(1, 2, 3, 4, 5, 6, 7),
                'Weekday' => array(1, 2, 3, 4, 5),
                'WeekendDay' => array(6, 7)
            );
            sort($days);
            foreach ($groups as $key => $entry) {
                if ($days == $entry) {
                    return $key;
                }
            }
        }
        // convert day values
        foreach ($days as $key => $entry) {
            if (isset($_tm[$entry])) {
                $days[$key] = $_tm[$entry];
            }
        }
        // convert days to string
        $days = implode(' ', $days);
        // return converted days
        return $days;

    }

    /**
     * convert remote days of the month to event object days of the month
     *
     * @param string $days - remote days of the month values(s)
     *
     * @return array event object days of the month values(s)
     *
     * @since Release 1.0.0
     *
     * @psalm-pure
     */
    public function fromDaysOfMonth(string $days): array
    {

        // convert days to array
        $days = explode(' ', $days);
        // return converted days
        return $days;

    }

    /**
     * convert event object days of the month to remote days of the month
     *
     * @param array $days - event object days of the month values(s)
     *
     * @return string remote days of the month values(s)
     *
     * @since Release 1.0.0
     *
     * @psalm-pure
     */
    public function toDaysOfMonth(array $days): string
    {

        // convert days to string
        $days = implode(' ', $days);
        // return converted days
        return $days;

    }

    /**
     * convert remote week of the month to event object week of the month
     *
     * @param string $weeks - remote week of the month values(s)
     *
     * @return array event object week of the month values(s)
     *
     * @since Release 1.0.0
     *
     * @psalm-pure
     */
    public function fromWeekOfMonth(string $weeks): array
    {

        // weeks conversion reference
        $_tm = array(
            'First' => 1,
            'Second' => 2,
            'Third' => 3,
            'Fourth' => 4,
            'Last' => -1
        );
        // convert weeks to array
        $weeks = explode(' ', $weeks);
        // convert week values
        foreach ($weeks as $key => $entry) {
            if (isset($_tm[$entry])) {
                $weeks[$key] = $_tm[$entry];
            }
        }
        // return converted weeks
        return $weeks;

    }

    /**
     * convert event object week of the month to remote week of the month
     *
     * @param array $weeks - event object week of the month values(s)
     *
     * @return string remote week of the month values(s)
     *
     * @since Release 1.0.0
     *
     * @psalm-pure
     */
    public function toWeekOfMonth(array $weeks): string
    {

        // weeks conversion reference
        $_tm = array(
            1 => 'First',
            2 => 'Second',
            3 => 'Third',
            4 => 'Fourth',
            -1 => 'Last',
            -2 => 'Fourth'
        );
        // convert week values
        foreach ($weeks as $key => $entry) {
            if (isset($_tm[$entry])) {
                $weeks[$key] = $_tm[$entry];
            }
        }
        // convert weeks to string
        $weeks = implode(',', $weeks);
        // return converted weeks
        return $weeks;

    }

    /**
     * convert remote month of the year to event object month of the year
     *
     * @param string $months - remote month of the year values(s)
     *
     * @return array event object month of the year values(s)
     *
     * @since Release 1.0.0
     *
     * @psalm-pure
     */
    public function fromMonthOfYear(string $months): array
    {

        // months conversion reference
        $_tm = array(
            'January' => 1,
            'February' => 2,
            'March' => 3,
            'April' => 4,
            'May' => 5,
            'June' => 6,
            'July' => 7,
            'August' => 8,
            'September' => 9,
            'October' => 10,
            'November' => 11,
            'December' => 12
        );
        // convert months to array
        $months = explode(' ', $months);
        // convert month values
        foreach ($months as $key => $entry) {
            if (isset($_tm[$entry])) {
                $months[$key] = $_tm[$entry];
            }
        }
        // return converted months
        return $months;

    }

    /**
     * convert event object month of the year to remote month of the year
     *
     * @param array $weeks - event object month of the year values(s)
     *
     * @return string remote month of the year values(s)
     *
     * @since Release 1.0.0
     *
     * @psalm-pure
     */
    public function toMonthOfYear(array $months): string
    {

        // months conversion reference
        $_tm = array(
            1 => 'January',
            2 => 'February',
            3 => 'March',
            4 => 'April',
            5 => 'May',
            6 => 'June',
            7 => 'July',
            8 => 'August',
            9 => 'September',
            10 => 'October',
            11 => 'November',
            12 => 'December'
        );
        // convert month values
        foreach ($months as $key => $entry) {
            if (isset($_tm[$entry])) {
                $month = $_tm[$entry];
                break;
            }
        }
        // evaluate if month was set
        if (empty($month)) {
            $month = 'Null';
        }
        // return converted months
        return $month;

    }

    /**
     * convert remote sensitivity to event object sensitivity
     *
     * @param string $level - remote sensitivity value
     *
     * @return int event object sensitivity value
     *
     * @since Release 1.0.0
     *
     * @psalm-pure
     */
    public function fromSensitivity(?string $level): int
    {

        // sensitivity conversion reference
        $levels = array(
            'Normal' => 0,
            'Personal' => 1,
            'Private' => 2,
            'Confidential' => 3
        );
        // evaluate if sensitivity value exists
        if (isset($levels[$level])) {
            // return converted sensitivity value
            return $levels[$level];
        } else {
            // return default sensitivity value
            return 0;
        }

    }

    /**
     * convert event object sensitivity to remote sensitivity
     *
     * @param int $level - event object sensitivity value
     *
     * @return string remote sensitivity value
     *
     * @since Release 1.0.0
     *
     * @psalm-pure
     */
    public function toSensitivity(?int $level): string
    {

        // sensitivity conversion reference
        $levels = array(
            0 => 'Normal',
            1 => 'Personal',
            2 => 'Private',
            3 => 'Confidential'
        );
        // evaluate if sensitivity value exists
        if (isset($levels[$level])) {
            // return converted sensitivity value
            return $levels[$level];
        } else {
            // return default sensitivity value
            return 'Normal';
        }

    }

    /**
     * convert remote importance to event object priority
     *
     * @param string $level - remote importance value
     *
     * @return int event object priority value
     *
     * @since Release 1.0.0
     *
     * @psalm-pure
     */
    public function fromImportance(?string $level): int
    {

        // EWS: 0 = low, 1 = normal (default), 2 = high
        // VEVENT: 0 = undefined, 1-3 = high, 4-6 = normal, 7-9 = low

        // evaluate remote level and return local equvialent
        if ($level == 'High') {
            return 2;        // high priority
        } elseif ($level == 'Low') {
            return 8;        // low priority
        } else {
            return 5;        // normal priority
        }

    }

    /**
     * convert event object priority to remote importance
     *
     * @param int $level - event object priority value
     *
     * @return string remote importance value
     *
     * @since Release 1.0.0
     *
     * @psalm-pure
     */
    public function toImportance(?int $level): string
    {

        // EWS: 0 = low, 1 = normal (default), 2 = high
        // VEVENT: 0 = undefined, 1-3 = high, 4-6 = normal, 7-9 = low

        // evaluate local level and return remote equvialent
        if ($level > 0 && $level < 4) {
            return 'High';        // high priority
        } elseif ($level > 6 && $level < 10) {
            return 'Low';        // low priority
        } else {
            return 'Normal';    // normal priority
        }

    }

    /**
     * convert remote attendee response to event object response
     *
     * @param string $response - remote attendee response value
     *
     * @return string event object attendee response value
     *
     * @since Release 1.0.0
     *
     * @psalm-pure
     */
    public function fromAttendeeResponse(?string $response): string
    {

        // response conversion reference
        $responses = array(
            'Accept' => 'A',
            'Decline' => 'D',
            'Tentative' => 'T',
            'Organizer' => 'O',
            'Unknown' => 'U',
            'NoResponseReceived' => 'N'
        );
        // evaluate if response value exists
        if (isset($responses[$response])) {
            // return converted response value
            return $responses[$response];
        } else {
            // return default response value
            return 'N';
        }

    }

    /**
     * convert event object attendee response to remote attendee response
     *
     * @param string $response - event object attendee response value
     *
     * @return string remote attendee response value
     *
     * @since Release 1.0.0
     *
     * @psalm-pure
     */
    public function toAttendeeResponse(?string $response): string
    {

        // response conversion reference
        $responses = array(
            'A' => 'Accept',
            'D' => 'Decline',
            'T' => 'Tentative',
            'O' => 'Organizer',
            'U' => 'Unknown',
            'N' => 'NoResponseReceived'
        );
        // evaluate if response value exists
        if (isset($responses[$response])) {
            // return converted response value
            return $responses[$response];
        } else {
            // return default response value
            return 'NoResponseReceived';
        }

    }

}
