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

use Datetime;
use DateTimeZone;
use OCA\EWS\Components\EWS\ArrayType\ArrayOfStringsType;
use OCA\EWS\Components\EWS\EWSClient;
use OCA\EWS\Components\EWS\Type\AbsoluteMonthlyRecurrencePatternType;
use OCA\EWS\Components\EWS\Type\AbsoluteYearlyRecurrencePatternType;
use OCA\EWS\Components\EWS\Type\BodyType;
use OCA\EWS\Components\EWS\Type\DailyRecurrencePatternType;
use OCA\EWS\Components\EWS\Type\EndDateRecurrenceRangeType;
use OCA\EWS\Components\EWS\Type\FileAttachmentType;
use OCA\EWS\Components\EWS\Type\ItemIdType;
use OCA\EWS\Components\EWS\Type\NoEndRecurrenceRangeType;
use OCA\EWS\Components\EWS\Type\NumberedRecurrenceRangeType;
use OCA\EWS\Components\EWS\Type\RecurrenceType;
use OCA\EWS\Components\EWS\Type\RelativeMonthlyRecurrencePatternType;
use OCA\EWS\Components\EWS\Type\RelativeYearlyRecurrencePatternType;
use OCA\EWS\Components\EWS\Type\WeeklyRecurrencePatternType;
use OCA\EWS\Objects\TaskObject;
use Psr\Log\LoggerInterface;

class RemoteTasksService2007 extends RemoteTasksService
{

    /**
     * @psalm-mutation-free
     */
    public function __construct(string              $appName,
                                LoggerInterface     $logger,
                                RemoteCommonService $RemoteCommonService)
    {
        parent::__construct($appName, $logger, $RemoteCommonService);
    }

    /**
     * @psalm-external-mutation-free
     */
    public function configure($configuration, EWSClient $DataStore): void
    {

        parent::configure($configuration, $DataStore);

        // assign configuration
        $this->Configuration = $configuration;
        // assign remote data store
        $this->DataStore = $DataStore;
        // assign timezones
        $this->SystemTimeZone = $configuration->SystemTimeZone;
        $this->UserTimeZone = $configuration->UserTimeZone;

    }

    /**
     * update collection item in remote storage
     *
     * @param string $cid - Collection ID
     * @param string $iid - Collection Item ID
     * @param TaskObject $so - Source Data
     *
     * @return TaskObject
     * @since Release 1.0.15
     *
     */
    public function updateCollectionItem(string $cid, string $iid, string $istate, TaskObject $so): ?TaskObject
    {

        // request modifications array
        $rm = array();
        // request deletions array
        $rd = array();
        // UUID
        if (!empty($so->UUID)) {
            $rm[] = $this->updateFieldExtendedByName('PublicStrings', 'DAV:uid', 'String', $so->UUID);
        } else {
            $rd[] = $this->deleteFieldExtendedByName('PublicStrings', 'DAV:uid', 'String');
        }

        // Starts On
        if (!empty($so->StartsOn)) {
            // clone start date
            $dt = clone $so->StartsOn;
            // change timezone on cloned date
            $dt->setTimezone(new DateTimeZone('UTC'));
            // construct start time attribute
            $rm[] = $this->updateFieldUnindexed('task:StartDate', 'StartDate', $dt->format('Y-m-d\\TH:i:s\Z'));
            // destroy temporary variable(s)
            unset($dt);
        } else {
            $rd[] = $this->deleteFieldUnindexed('task:StartDate');
        }
        // Due On
        if (!empty($so->DueOn)) {
            // clone end date
            $dt = clone $so->DueOn;
            // change timezone on cloned date
            $dt->setTimezone(new DateTimeZone('UTC'));
            // construct end time property
            $rm[] = $this->updateFieldUnindexed('task:DueDate', 'DueDate', $dt->format('Y-m-d\\TH:i:s\Z'));
            // destroy temporary variable(s)
            unset($dt);
        } else {
            $rd[] = $this->deleteFieldUnindexed('task:DueDate');
        }
        // Completed On
        if (!empty($so->CompletedOn)) {
            // clone end date
            $dt = clone $so->CompletedOn;
            // change timezone on cloned date
            $dt->setTimezone(new DateTimeZone('UTC'));
            // construct end time property
            $rm[] = $this->updateFieldUnindexed('task:CompleteDate', 'CompleteDate', $dt->format('Y-m-d\\TH:i:s\Z'));
            // destroy temporary variable(s)
            unset($dt);
        } else {
            $rd[] = $this->deleteFieldUnindexed('task:CompleteDate');
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
                    'Text',
                    $so->Notes
                ));
        } else {
            $rd[] = $this->deleteFieldUnindexed('item:Body');
        }
        // Progress
        if (!empty($so->Progress)) {
            $rm[] = $this->updateFieldUnindexed('task:PercentComplete', 'PercentComplete', $so->Progress);
        } else {
            $rm[] = $this->updateFieldUnindexed('task:PercentComplete', 'PercentComplete', 0);
        }
        // Status
        if (!empty($so->Status)) {
            $rm[] = $this->updateFieldUnindexed('task:Status', 'Status', $this->toStatus($so->Status));
        } else {
            $rm[] = $this->updateFieldUnindexed('task:Status', 'Status', $this->toStatus('N'));
        }
        // Priority
        if (!empty($so->Priority)) {
            $rm[] = $this->updateFieldUnindexed('item:Importance', 'Importance', $this->toImportance($so->Priority));
        } else {
            $rm[] = $this->updateFieldUnindexed('item:Importance', 'Importance', $this->toImportance(5));
        }
        // Sensitivity
        if (!empty($so->Sensitivity)) {
            $rm[] = $this->updateFieldUnindexed('item:Sensitivity', 'Sensitivity', $this->toSensitivity($so->Sensitivity));
        } else {
            $rm[] = $this->updateFieldUnindexed('item:Sensitivity', 'Sensitivity', $this->toSensitivity(0));
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
            }
            // Conclusion
            if (!empty($so->Occurrence->Concludes)) {
                $f->EndDateRecurrence = new EndDateRecurrenceRangeType();
                if ($so->Origin == 'L') {
                    // subtract 1 day to adjust in how the end date is calculated in NC and EWS
                    $f->EndDateRecurrence->EndDate = date_modify(clone $so->Occurrence->Concludes, '-1 day')->format('Y-m-d\TH:i:s');
                } else {
                    $f->EndDateRecurrence->EndDate = $so->Occurrence->Concludes->format('Y-m-d\TH:i:s');
                }
            }
            // No Iterations And No Conclusion Date
            if (empty($so->Occurrence->Iterations) && empty($so->Occurrence->Concludes)) {
                $f->NoEndRecurrence = new NoEndRecurrenceRangeType();
                $f->NoEndRecurrence->StartDate = $so->StartsOn->format('Y-m-d');
            }

            // Based on Precision
            // Daily Task
            if ($so->Occurrence->Precision == 'D') {
                $f->DailyRecurrence = new DailyRecurrencePatternType();
                if (!empty($so->Occurrence->Interval)) {
                    $f->DailyRecurrence->Interval = $so->Occurrence->Interval;
                } else {
                    $f->DailyRecurrence->Interval = '1';
                }
            } // Weekly Task
            elseif ($so->Occurrence->Precision == 'W') {
                $f->WeeklyRecurrence = new WeeklyRecurrencePatternType();
                if (!empty($so->Occurrence->Interval)) {
                    $f->WeeklyRecurrence->Interval = $so->Occurrence->Interval;
                } else {
                    $f->WeeklyRecurrence->Interval = '1';
                }
                $f->WeeklyRecurrence->DaysOfWeek = $this->toDaysOfWeek($so->Occurrence->OnDayOfWeek);
                $f->WeeklyRecurrence->FirstDayOfWeek = 'Monday';
            } // Monthly Task
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
            } // Yearly Task
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

            $rm[] = $this->updateFieldUnindexed('task:Recurrence', 'Recurrence', $f);
        } else {
            $rd[] = $this->deleteFieldUnindexed('task:Recurrence');
        }
        // execute command
        $rs = $this->RemoteCommonService->updateItem($this->DataStore, $cid, $iid, $istate, null, $rm, $rd);
        // process response
        if ($rs->Task[0]) {
            $to = clone $so;
            $to->ID = $rs->Task[0]->ItemId->Id;
            $to->CID = $cid;
            $to->State = $rs->Task[0]->ItemId->ChangeKey;
            // deposit attachment(s)
            if (count($to->Attachments) > 0) {
                // create attachments in remote data store
                $to->Attachments = $this->createCollectionItemAttachment($to->ID, $to->Attachments);
                $to->State = $to->Attachments[0]->AffiliateState;
            }
            return $to;
        } else {
            return null;
        }

    }

    /**
     * update collection item with uuid in remote storage
     *
     * @param string $cid - Collection ID
     * @param string $iid - Collection Item ID
     * @param string $cid - Collection Item UUID
     *
     * @return object Status Object - item id, item uuid, item state token / Null - failed to create
     * @since Release 1.0.15
     *
     */
    public function updateCollectionItemUUID(string $cid, string $iid, string $istate, string $uuid): ?object
    {
        // request modifications array
        $rm = array();
        // construct update command object
        $rm[] = $this->updateFieldExtendedByName('PublicStrings', 'DAV:uid', 'String', $uuid);
        // execute request
        $rs = $this->RemoteCommonService->updateItem($this->DataStore, $cid, $iid, $istate, null, $rm, null);
        // return response
        if ($rs->Task[0]) {
            return (object)array('ID' => $rs->Task[0]->ItemId->Id, 'UID' => $uuid, 'State' => $rs->Task[0]->ItemId->ChangeKey);
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
        //
        try {
            $result = $this->RemoteCommonService->deleteItem($this->DataStore, array($o), 'HardDelete', ['TaskOccurrences' => 'AllOccurrences']);
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'ErrorItemNotFound')) {
                $result = true;
            } else {
                throw $e;
            }
        }

        if ($result) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * create collection item attachment in local storage
     *
     * @param string $aid - Affiliation ID
     * @param array $sc - Collection of TaskAttachmentObject(S)
     *
     * @return string
     * @since Release 1.0.16
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
            $co->Name = $entry->Name;
            $co->ContentId = $entry->Name;
            $co->ContentType = $entry->Type;

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

}
