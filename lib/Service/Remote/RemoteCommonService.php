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

use Exception;
use OCA\EWS\Components\EWS\ArrayType\ArrayOfFoldersType;
use OCA\EWS\Components\EWS\ArrayType\NonEmptyArrayOfAllItemsType;
use OCA\EWS\Components\EWS\ArrayType\NonEmptyArrayOfAttachmentsType;
use OCA\EWS\Components\EWS\ArrayType\NonEmptyArrayOfBaseFolderIdsType;
use OCA\EWS\Components\EWS\ArrayType\NonEmptyArrayOfBaseItemIdsType;
use OCA\EWS\Components\EWS\ArrayType\NonEmptyArrayOfItemChangeDescriptionsType;
use OCA\EWS\Components\EWS\ArrayType\NonEmptyArrayOfNotificationEventTypesType;
use OCA\EWS\Components\EWS\ArrayType\NonEmptyArrayOfPathsToElementType;
use OCA\EWS\Components\EWS\ArrayType\NonEmptyArrayOfRequestAttachmentIdsType;
use OCA\EWS\Components\EWS\ArrayType\NonEmptyArrayOfTimeZoneIdType;
use OCA\EWS\Components\EWS\Enumeration\ResponseClassType;
use OCA\EWS\Components\EWS\EWSClient;
use OCA\EWS\Components\EWS\Request\CreateAttachmentType;
use OCA\EWS\Components\EWS\Request\CreateFolderType;
use OCA\EWS\Components\EWS\Request\CreateItemType;
use OCA\EWS\Components\EWS\Request\DeleteAttachmentType;
use OCA\EWS\Components\EWS\Request\DeleteFolderType;
use OCA\EWS\Components\EWS\Request\DeleteItemType;
use OCA\EWS\Components\EWS\Request\FindFolderType;
use OCA\EWS\Components\EWS\Request\FindItemType;
use OCA\EWS\Components\EWS\Request\GetAttachmentType;
use OCA\EWS\Components\EWS\Request\GetEventsType;
use OCA\EWS\Components\EWS\Request\GetFolderType;
use OCA\EWS\Components\EWS\Request\GetItemType;
use OCA\EWS\Components\EWS\Request\GetServerTimeZonesType;
use OCA\EWS\Components\EWS\Request\SubscribeType;
use OCA\EWS\Components\EWS\Request\SyncFolderItemsType;
use OCA\EWS\Components\EWS\Request\UnsubscribeType;
use OCA\EWS\Components\EWS\Request\UpdateItemType;
use OCA\EWS\Components\EWS\Type\CalendarFolderType;
use OCA\EWS\Components\EWS\Type\ConstantValueType;
use OCA\EWS\Components\EWS\Type\ContactsFolderType;
use OCA\EWS\Components\EWS\Type\DistinguishedFolderIdType;
use OCA\EWS\Components\EWS\Type\FieldURIOrConstantType;
use OCA\EWS\Components\EWS\Type\FolderIdType;
use OCA\EWS\Components\EWS\Type\FolderResponseShapeType;
use OCA\EWS\Components\EWS\Type\FolderType;
use OCA\EWS\Components\EWS\Type\IndexedPageViewType;
use OCA\EWS\Components\EWS\Type\IsEqualToType;
use OCA\EWS\Components\EWS\Type\ItemChangeType;
use OCA\EWS\Components\EWS\Type\ItemIdType;
use OCA\EWS\Components\EWS\Type\ItemResponseShapeType;
use OCA\EWS\Components\EWS\Type\PathToExtendedFieldType;
use OCA\EWS\Components\EWS\Type\PathToUnindexedFieldType;
use OCA\EWS\Components\EWS\Type\PullSubscriptionRequestType;
use OCA\EWS\Components\EWS\Type\RequestAttachmentIdType;
use OCA\EWS\Components\EWS\Type\RestrictionType;
use OCA\EWS\Components\EWS\Type\SearchFolderType;
use OCA\EWS\Components\EWS\Type\TargetFolderIdType;
use OCA\EWS\Components\EWS\Type\TasksFolderType;
use Psr\Log\LoggerInterface;

/**
 * Remote Common Service Class
 *
 * This class contains a collection of function that build EWS commands
 * and execute them on a Exchange Data Store
 *
 * @since Release 1.0.0
 */
class RemoteCommonService {

	// Message Descriptors
	const DESCRIPTOR_REMOTE_ERROR = 'Remote Error: ';
	const DESCRIPTOR_REMOTE_WARNING = 'Remote Warning: ';
	// Folder Types
	const TYPE_FOLDER_BASE = 'msgfolderroot';
	const TYPE_FOLDER_PUBLIC = 'publicfoldersroot';
	//
	const PS_PUBLIC_STRINGS	= '00020329-0000-0000-C000-000000000046';
	// Search Extent
	const SCOPE_SEARCH_BROAD = 'Deep';
	const SCOPE_SEARCH_NARROW = 'Shallow';
	// Attribute Scopes
	const SCOPE_ATTRIBUTES_BASIC = 'IdOnly';
	const SCOPE_ATTRIBUTES_PRESET = 'Default';
	const SCOPE_ATTRIBUTES_ENTIRE = 'AllProperties';

    /**
     * @psalm-mutation-free
     */
    public function __construct (string $appName,
                                 private LoggerInterface $logger) {
    }

	/**
     * retrieve list of all folders starting with root folder from remote storage
     *
     * @since Release 1.0.0
     *
	 * @param EWSClient $DataStore
	 * 		Storage Interface
	 * @param string $base
	 * 		Base Properties to return ( D - Default | A - All | I - ID Only )
	 * @param object $additional
	 * 		Additional Properties to return ( Object of NonEmptyArrayOfPathsToElementType )
	 * @return object
	 * 		Folder Object on success / Null on failure
	 */
	public function fetchFolders(EWSClient $DataStore, string $base = 'D', object $additional = null): ?object {

		// construct the request
		$request = new FindFolderType();
		// define start
		$request->ParentFolderIds = new NonEmptyArrayOfBaseFolderIdsType();
		$request->ParentFolderIds->DistinguishedFolderId[] = new DistinguishedFolderIdType(self::TYPE_FOLDER_BASE);
		// define recursion
		$request->Traversal = self::SCOPE_SEARCH_BROAD;
		// define required base properties
		$request->FolderShape = new FolderResponseShapeType();
		if ($base == 'A') {
			$request->FolderShape->BaseShape = self::SCOPE_ATTRIBUTES_ENTIRE;
		}
		elseif ($base == 'I') {
			$request->FolderShape->BaseShape = self::SCOPE_ATTRIBUTES_BASIC;
		}
		else  {
			$request->FolderShape->BaseShape = self::SCOPE_ATTRIBUTES_PRESET;
		}
		// define required additional properties
		if ($additional instanceof NonEmptyArrayOfPathsToElementType) {
			$request->FolderShape->AdditionalProperties = $additional;
		}
		// execute request
		$response = $DataStore->FindFolder($request);
		// process response
		$response = $response->ResponseMessages->FindFolderResponseMessage;
		$data = null;
		foreach ($response as $response_data) {
			// evaluate if response contained a error
			if ($response_data->ResponseClass == ResponseClassType::ERROR) {
				throw new Exception(self::DESCRIPTOR_REMOTE_ERROR . $response_data->ResponseCode . ' - ' . $response_data->MessageText);
			}
			// evaluate if response contained a warning
			elseif ($response_data->ResponseClass == ResponseClassType::WARNING) {
				$this->logger->warning(self::DESCRIPTOR_REMOTE_WARNING . $response_data->MessageText);
			}
			// extract data object from response
			$data = $response_data->RootFolder->Folders;
		}
		// return object or null
		return $data;

	}

	/**
     * retrieve list of specific folders starting with root folder from remote storage
     *
     * @since Release 1.0.0
     *
	 * @param EWSClient $DataStore
	 * 		Storage Interface
	 * @param string $type
	 *		Folder Type  ( IPF.Contact | IPF.Appointment | IPF.Task | IPF.Note )
	 * @param string $base
	 * 		Base Properties to return ( D - Default | A - All | I - ID Only )
	 * @param object $additional
	 * 		Additional Properties to return ( Object of NonEmptyArrayOfPathsToElementType )
	 * @param string $source
	 * 		Source domain ( U - User | P - Public )
	 * @return object
	 * 		Folder Object on success / Null on failure
	 */
	public function fetchFoldersByType(EWSClient $DataStore, string $type, string $base = 'D', object $additional = null, string $source = 'U'): ?object {

		// construct request
		$request = new FindFolderType();
		$request->FolderShape = new FolderResponseShapeType();
		// define start
		$request->ParentFolderIds = new NonEmptyArrayOfBaseFolderIdsType();
		if ($source == 'P') {
			// define base folder
			$request->ParentFolderIds->DistinguishedFolderId[] = new DistinguishedFolderIdType(self::TYPE_FOLDER_PUBLIC);
			// define recursion
			$request->Traversal = self::SCOPE_SEARCH_NARROW;
		}
		else {
			// define base folder
			$request->ParentFolderIds->DistinguishedFolderId[] = new DistinguishedFolderIdType(self::TYPE_FOLDER_BASE);
			// define recursion
			$request->Traversal = self::SCOPE_SEARCH_BROAD;
		}
		// define required base properties
		if ($base == 'A') {
			$request->FolderShape->BaseShape = self::SCOPE_ATTRIBUTES_ENTIRE;
		}
		elseif ($base == 'I') {
			$request->FolderShape->BaseShape = self::SCOPE_ATTRIBUTES_BASIC;
		}
		else  {
			$request->FolderShape->BaseShape = self::SCOPE_ATTRIBUTES_PRESET;
		}
		// define required additional properties
		if ($additional instanceof NonEmptyArrayOfPathsToElementType) {
			$request->FolderShape->AdditionalProperties = $additional;
		}
		// define search criteria
		$request->Restriction = new RestrictionType();
		$request->Restriction->IsEqualTo = new IsEqualToType();
		$request->Restriction->IsEqualTo->FieldURI = new PathToUnindexedFieldType('folder:FolderClass');
		$request->Restriction->IsEqualTo->FieldURIOrConstant = new FieldURIOrConstantType();
		$request->Restriction->IsEqualTo->FieldURIOrConstant->Constant = new ConstantValueType($type);
		// execute request
		$response = $DataStore->FindFolder($request);
		// process response
		$response = $response->ResponseMessages->FindFolderResponseMessage;
		$data = null;
		foreach ($response as $response_data) {
			// evaluate if response contained a error
			if ($response_data->ResponseClass == ResponseClassType::ERROR) {
				throw new Exception(self::DESCRIPTOR_REMOTE_ERROR . $response_data->ResponseCode . ' - ' . $response_data->MessageText);
			}
			// evaluate if response contained a warning
			elseif ($response_data->ResponseClass == ResponseClassType::WARNING) {
				$this->logger->warning(self::DESCRIPTOR_REMOTE_WARNING . $response_data->MessageText);
			}
			// extract data object from response
			$data = $response_data->RootFolder->Folders;
		}
		// return object or null
		return $data;

	}

	/**
     * retrieve all information for specific folder from remote storage
     *
     * @since Release 1.0.0
     *
	 * @param EWSClient $DataStore
	 * 		Storage Interface
	 * @param string $fid
	 * 		Folder ID
	 * @param string $ftype
	 * 		Folder ID Type (True - Distinguished / False - Normal)
	 * @param string $base
	 * 		Base Properties to return ( D - Default | A - All | I - ID Only )
	 * @param object $additional
	 * 		Additional Properties to return ( Object of NonEmptyArrayOfPathsToElementType )
	 * @return object
	 * 		Folder Object on success / Null on failure
	 */
	public function fetchFolder(EWSClient $DataStore, string $fid, bool $ftype = false, string $base = 'D', object $additional = null): ?object {

		// construct request
		$request = new GetFolderType();
		// define target
		$request->FolderIds = new NonEmptyArrayOfBaseFolderIdsType();
		if ($ftype) {
			$request->FolderIds->DistinguishedFolderId[] = new DistinguishedFolderIdType($fid);
		} else {
			$request->FolderIds->FolderId[] = new FolderIdType($fid);
		}
		// define required base properties
		$request->FolderShape = new FolderResponseShapeType();
		if ($base == 'A') {
			$request->FolderShape->BaseShape = self::SCOPE_ATTRIBUTES_ENTIRE;
		}
		elseif ($base == 'I') {
			$request->FolderShape->BaseShape = self::SCOPE_ATTRIBUTES_BASIC;
		}
		else  {
			$request->FolderShape->BaseShape = self::SCOPE_ATTRIBUTES_PRESET;
		}
		// define required additional properties
		if ($additional instanceof NonEmptyArrayOfPathsToElementType) {
			$request->FolderShape->AdditionalProperties = $additional;
		}
		// execute request
		$response = $DataStore->GetFolder($request);
		$response = $response->ResponseMessages->GetFolderResponseMessage;
		// process response
		$data = null;
		foreach ($response as $response_data) {
			// evaluate if response contained a error
			if ($response_data->ResponseClass == ResponseClassType::ERROR) {
				throw new Exception(self::DESCRIPTOR_REMOTE_ERROR . $response_data->ResponseCode . ' - ' . $response_data->MessageText);
			}
			// evaluate if response contained a warning
			elseif ($response_data->ResponseClass == ResponseClassType::WARNING) {
				$this->logger->warning(self::DESCRIPTOR_REMOTE_WARNING . $response_data->MessageText);
			}
			// extract data object from response
			$data = $response_data->Folders;
		}
		// return object or null
		return $data;

	}

	/**
     * create folder in remote storage
	 *
     * @since Release 1.0.0
     *
	 * @param EWSClient $DataStore
	 * 		Storage Interface
	 * @param string $fid
	 * 		Folder ID
	 * @param string $data
	 * 		Item Data
	 * @return object
	 * 		Folders Object on success / Null on failure
	 */
	public function createFolder(EWSClient $DataStore, string $fid, object $data, bool $ftype = false): ?object {

		// construct request
		$request = new CreateFolderType();
		// define target
		$request->ParentFolderId = new TargetFolderIdType();
		if ($ftype) {
			$request->ParentFolderId->DistinguishedFolderId = new DistinguishedFolderIdType($fid);
		} else {
			$request->ParentFolderId->FolderId = new FolderIdType($fid);
		}
		// define object to create
		$request->Folders = new ArrayOfFoldersType();
		if ($data instanceof CalendarFolderType) {
			$request->Folders->CalendarFolder[] = $data;
		}
		elseif ($data instanceof ContactsFolderType) {
			$request->Folders->ContactsFolder[] = $data;
		}
		elseif ($data instanceof FolderType) {
			$request->Folders->Folder[] = $data;
		}
		elseif ($data instanceof SearchFolderType) {
			$request->Folders->SearchFolder[] = $data;
		}
		elseif ($data instanceof TasksFolderType) {
			$request->Folders->TasksFolder[] = $data;
		}
		// execute request
		$response = $DataStore->CreateFolder($request);
		// process response
		$response = $response->ResponseMessages->CreateFolderResponseMessage;
		$data = null;
		foreach ($response as $response_data) {
			// evaluate if response contained a error
			if ($response_data->ResponseClass == ResponseClassType::ERROR) {
				throw new Exception(self::DESCRIPTOR_REMOTE_ERROR . $response_data->ResponseCode . ' - ' . $response_data->MessageText);
			}
			// evaluate if response contained a warning
			elseif ($response_data->ResponseClass == ResponseClassType::WARNING) {
				$this->logger->warning(self::DESCRIPTOR_REMOTE_WARNING . $response_data->MessageText);
			}
			// extract data object from response
			$data = $response_data->Folders;
		}
		// return object or null
		return $data;

	}

	/**
     * delete folder from remote storage
     *
     * @since Release 1.0.0
     *
	 * @param EWSClient $DataStore
	 * 		Storage Interface
	 * @param string $ids
	 * 		Collection Id's List
	 * @param string $type
	 *
	 * @return object
	 * 		True on success / False on failure
	 */
	public function deleteFolder(EWSClient $DataStore, array $batch = null, string $type = 'SoftDelete'): ?bool {

		// construct request
		$request = new DeleteFolderType();
		$request->DeleteType = $type;
		// define objects to delete
		$request->FolderIds = new NonEmptyArrayOfBaseFolderIdsType($batch);
		// execute request
		$response = $DataStore->DeleteFolder($request);
		// process response
		$response = $response->ResponseMessages->DeleteFolderResponseMessage;
		$data = false;
		foreach ($response as $response_data) {
			// evaluate if response contained a error
			if ($response_data->ResponseClass == ResponseClassType::ERROR) {
				throw new Exception(self::DESCRIPTOR_REMOTE_ERROR . $response_data->ResponseCode . ' - ' . $response_data->MessageText);
			}
			// evaluate if response contained a warning
			elseif ($response_data->ResponseClass == ResponseClassType::WARNING) {
				$this->logger->warning(self::DESCRIPTOR_REMOTE_WARNING . $response_data->MessageText);
			}
			// extract data object from response
			$data = true;
		}
		// return object or null
		return $data;

	}

	/**
     * retrieve list of changes for specific folder from remote storage
     *
     * @since Release 1.0.0
     *
	 * @param EWSClient $DataStore
	 * 		Storage Interface
	 * @param string $fid
	 * 		Folder ID
	 * @param string $state
	 * 		Folder Synchronization State
	 * @param bool $ftype
	 * 		Folder ID Type (True - Distinguished / False - Normal)
	 * @param int $max
	 * 		Maximum Number of changes to list
	 * @param string $base
	 * 		Base Properties to return ( D - Default | A - All | I - ID Only )
	 * @param object $additional
	 * 		Additional Properties to return ( Object of NonEmptyArrayOfPathsToElementType )
	 * @return object
	 * 		Folder Changes Object on success / Null on failure
	 */
	public function fetchFolderChanges(EWSClient $DataStore, string $fid, string $state, bool $ftype = false, int $max = 512, string $base = 'I', object $additional = null): object {

		// construct request
		$request = new SyncFolderItemsType();
		// define target
		$request->SyncFolderId = new TargetFolderIdType();
		if ($ftype) {
			$request->SyncFolderId->DistinguishedFolderId = new DistinguishedFolderIdType($fid);
		} else {
			$request->SyncFolderId->FolderId = new FolderIdType($fid);
		}
		// define start
		$request->SyncState = $state;
		$request->MaxChangesReturned = $max;
		// define required base properties
		$request->ItemShape = new ItemResponseShapeType();
		if ($base == 'A') {
			$request->ItemShape->BaseShape = self::SCOPE_ATTRIBUTES_ENTIRE;
		}
		elseif ($base == 'I') {
			$request->ItemShape->BaseShape = self::SCOPE_ATTRIBUTES_BASIC;
		}
		else  {
			$request->ItemShape->BaseShape = self::SCOPE_ATTRIBUTES_PRESET;
		}
		// define required additional properties
		if ($additional instanceof NonEmptyArrayOfPathsToElementType) {
			$request->ItemShape->AdditionalProperties = $additional;
		}
		/*
		else {
			$request->ItemShape->AdditionalProperties = new \OCA\EWS\Components\EWS\ArrayType\NonEmptyArrayOfPathsToElementType();
		}
		// define required essential properties

		$request->ItemShape->AdditionalProperties->ExtendedFieldURI[] = new \OCA\EWS\Components\EWS\Type\PathToExtendedFieldType(
			'PublicStrings',
			null,
			null,
			'DAV:id',
			null,
			'String'
		);
		$request->ItemShape->AdditionalProperties->ExtendedFieldURI[] = new \OCA\EWS\Components\EWS\Type\PathToExtendedFieldType(
			'PublicStrings',
			null,
			null,
			'DAV:uid',
			null,
			'String'
		);

		$request->ItemShape->AdditionalProperties->ExtendedFieldURI[] = new \OCA\EWS\Components\EWS\Type\PathToExtendedFieldType(
			null,
			null,
			null,
			null,
			'0x3007',
			'SystemTime'
		);
		$request->ItemShape->AdditionalProperties->ExtendedFieldURI[] = new \OCA\EWS\Components\EWS\Type\PathToExtendedFieldType(
			null,
			null,
			null,
			null,
			'0x3008',
			'SystemTime'
		);
		*/
		// execute request
		$response = $DataStore->SyncFolderItems($request);
		// process response
		$response = $response->ResponseMessages->SyncFolderItemsResponseMessage;
		$data = null;
		foreach ($response as $response_data) {
			// evaluate if response contained a error
			if ($response_data->ResponseClass == ResponseClassType::ERROR) {
				throw new Exception(self::DESCRIPTOR_REMOTE_ERROR . $response_data->ResponseCode . ' - ' . $response_data->MessageText);
			}
			// evaluate if response contained a warning
			elseif ($response_data->ResponseClass == ResponseClassType::WARNING) {
				$this->logger->warning(self::DESCRIPTOR_REMOTE_WARNING . $response_data->MessageText);
			}
			// extract data object from response
			$data = $response_data->Changes;
			$data->SyncToken = $response_data->SyncState;
		}
		// return object or null
		return $data;

	}

	/**
     * retrieve all items in specific folder from remote storage
     *
     * @since Release 1.0.0
     *
	 * @param EWSClient $DataStore
	 * 		Storage Interface
	 * @param string $fid
	 * 		Folder ID
	 * @param string $ftype
	 * 		Folder ID Type (True - Distinguished / False - Normal)
	 * @param string $ioffset
	 * 		Items Offset
	 * @param string $ilimit
	 * 		Items Limit
	 * @param string $base
	 * 		Base Properties to return ( D - Default | A - All | I - ID Only )
	 * @param object $additional
	 * 		Additional Properties to return ( Object of NonEmptyArrayOfPathsToElementType )
	 * @return object
	 * 		Item Object on success / Null on failure
	 */
	public function fetchItems(EWSClient $DataStore, string $fid, bool $ftype = false, int $ioffset = 0, int $ilimit = 512, string $base = 'I', object $additional = null): ?object {

		// construct request
		$request = new FindItemType();
		// define target
		$request->ParentFolderIds = new NonEmptyArrayOfBaseFolderIdsType();
		if ($ftype) {
			$request->ParentFolderIds->DistinguishedFolderId[] = new DistinguishedFolderIdType($fid);
		} else {
			$request->ParentFolderIds->FolderId[] = new FolderIdType($fid);
		}
		// define recursion
		$request->Traversal = self::SCOPE_SEARCH_NARROW;
		// define required base properties
		$request->ItemShape = new ItemResponseShapeType();
		if ($base == 'A') {
			$request->ItemShape->BaseShape = self::SCOPE_ATTRIBUTES_ENTIRE;
		}
		elseif ($base == 'I') {
			$request->ItemShape->BaseShape = self::SCOPE_ATTRIBUTES_BASIC;
		}
		else  {
			$request->ItemShape->BaseShape = self::SCOPE_ATTRIBUTES_PRESET;
		}
		// define required additional properties
		if ($additional instanceof NonEmptyArrayOfPathsToElementType) {
			$request->ItemShape->AdditionalProperties = $additional;
		}
		// define paging
		$request->IndexedPageItemView = new IndexedPageViewType('Beginning', $ioffset, $ilimit);
		// execute request
		$response = $DataStore->FindItem($request);
		// process response
		$response = $response->ResponseMessages->FindItemResponseMessage;
		$data = null;
		foreach ($response as $response_data) {
			// evaluate if response contained a error
			if ($response_data->ResponseClass == ResponseClassType::ERROR) {
				throw new Exception(self::DESCRIPTOR_REMOTE_ERROR . $response_data->ResponseCode . ' - ' . $response_data->MessageText);
			}
			// evaluate if response contained a warning
			elseif ($response_data->ResponseClass == ResponseClassType::WARNING) {
				$this->logger->warning(self::DESCRIPTOR_REMOTE_WARNING . $response_data->MessageText);
			}
			// extract data object from response
			$data = $response_data->RootFolder->Items;
		}
		// return object or null
		return $data;

	}

	/**
     * retrieve information for specific item from remote storage
     *
     * @since Release 1.0.0
     *
	 * @param EWSClient $DataStore
	 * 		Storage Interface
	 * @param string $uuid
	 * 		Item UUID
	 * @param string $fid
	 * 		Folder ID
	 * @param string $ftype
	 * 		Folder ID Type (True - Distinguished / False - Normal)
	 * @param string $base
	 * 		Base Properties to return ( D - Default | A - All | I - ID Only )
	 * @param object $additional
	 * 		Additional Properties to return ( Object of NonEmptyArrayOfPathsToElementType )
	 * @return object
	 * 		Item Object on success / Null on failure
	 */
	public function findItem(EWSClient $DataStore, string $fid, object $restriction, bool $ftype = false, string $base = 'D', object $additional = null): ?object {

		// construct request
		$request = new FindItemType();
		// define target
		$request->ParentFolderIds = new NonEmptyArrayOfBaseFolderIdsType();
		if ($ftype) {
			$request->ParentFolderIds->DistinguishedFolderId[] = new DistinguishedFolderIdType($fid);
		} else {
			$request->ParentFolderIds->FolderId[] = new FolderIdType($fid);
		}
		// define recursion
		$request->Traversal = self::SCOPE_SEARCH_NARROW;
		// define required base properties
		$request->ItemShape = new ItemResponseShapeType();
		if ($base == 'A') {
			$request->ItemShape->BaseShape = self::SCOPE_ATTRIBUTES_ENTIRE;
		}
		elseif ($base == 'I') {
			$request->ItemShape->BaseShape = self::SCOPE_ATTRIBUTES_BASIC;
		}
		else  {
			$request->ItemShape->BaseShape = self::SCOPE_ATTRIBUTES_PRESET;
		}
		// define required additional properties
		if ($additional instanceof NonEmptyArrayOfPathsToElementType) {
			$request->ItemShape->AdditionalProperties = $additional;
		}
		else {
			$request->ItemShape->AdditionalProperties = new NonEmptyArrayOfPathsToElementType();
		}
		// define required essential properties
		$request->ItemShape->AdditionalProperties->ExtendedFieldURI[] = new PathToExtendedFieldType(
			'PublicStrings',
			null,
			null,
			'DAV:id',
			null,
			'String'
		);
		$request->ItemShape->AdditionalProperties->ExtendedFieldURI[] = new PathToExtendedFieldType(
			'PublicStrings',
			null,
			null,
			'DAV:uid',
			null,
			'String'
		);
		/*
		$request->ItemShape->AdditionalProperties->ExtendedFieldURI[] = new \OCA\EWS\Components\EWS\Type\PathToExtendedFieldType(
			null,
			null,
			null,
			null,
			'0x3007',
			'SystemTime'
		);
		$request->ItemShape->AdditionalProperties->ExtendedFieldURI[] = new \OCA\EWS\Components\EWS\Type\PathToExtendedFieldType(
			null,
			null,
			null,
			null,
			'0x3008',
			'SystemTime'
		);
		*/
		// define paging
		$request->IndexedPageItemView = new IndexedPageViewType('Beginning', 0, 512);
		// define criteria
		$request->Restriction = $restriction;
		// execute request
		$response = $DataStore->FindItem($request);
		// process response
		$response = $response->ResponseMessages->FindItemResponseMessage;
		$data = null;
		foreach ($response as $response_data) {
			// evaluate if response contained a error
			if ($response_data->ResponseClass == ResponseClassType::ERROR) {
				throw new Exception(self::DESCRIPTOR_REMOTE_ERROR . $response_data->ResponseCode . ' - ' . $response_data->MessageText);
			}
			// evaluate if response contained a warning
			elseif ($response_data->ResponseClass == ResponseClassType::WARNING) {
				$this->logger->warning(self::DESCRIPTOR_REMOTE_WARNING . $response_data->MessageText);
			}
			// extract data object from response
			$data = $response_data->RootFolder->Items;
		}
		// return object or null
		return $data;

	}

	/**
     * retrieve all information for specific item by uuid from remote storage
     *
     * @since Release 1.0.0
     *
	 * @param EWSClient $DataStore
	 * 		Storage Interface
	 * @param string $uuid
	 * 		Item UUID
	 * @param string $fid
	 * 		Folder ID
	 * @param string $ftype
	 * 		Folder ID Type (True - Distinguished / False - Normal)
	 * @param string $base
	 * 		Base Properties to return ( D - Default | A - All | I - ID Only )
	 * @param object $additional
	 * 		Additional Properties to return ( Object of NonEmptyArrayOfPathsToElementType )
	 * @return object
	 * 		Item Object on success / Null on failure
	 */
	public function findItemByUUID(EWSClient $DataStore, string $fid, string $uuid, bool $ftype = false, string $base = 'D', object $additional = null): ?object {

		// construct request
		$request = new FindItemType();
		// define target
		$request->ParentFolderIds = new NonEmptyArrayOfBaseFolderIdsType();
		if ($ftype) {
			$request->ParentFolderIds->DistinguishedFolderId[] = new DistinguishedFolderIdType($fid);
		} else {
			$request->ParentFolderIds->FolderId[] = new FolderIdType($fid);
		}
		// define recursion
		$request->Traversal = self::SCOPE_SEARCH_NARROW;
		// define required base properties
		$request->ItemShape = new ItemResponseShapeType();
		if ($base == 'A') {
			$request->ItemShape->BaseShape = self::SCOPE_ATTRIBUTES_ENTIRE;
		}
		elseif ($base == 'I') {
			$request->ItemShape->BaseShape = self::SCOPE_ATTRIBUTES_BASIC;
		}
		else  {
			$request->ItemShape->BaseShape = self::SCOPE_ATTRIBUTES_PRESET;
		}
		// define required additional properties
		if ($additional instanceof NonEmptyArrayOfPathsToElementType) {
			$request->ItemShape->AdditionalProperties = $additional;
		}
		else {
			$request->ItemShape->AdditionalProperties = new NonEmptyArrayOfPathsToElementType();
		}
		// define required essential properties
		$request->ItemShape->AdditionalProperties->ExtendedFieldURI[] = new PathToExtendedFieldType(
			'PublicStrings',
			null,
			null,
			'DAV:id',
			null,
			'String'
		);
		$request->ItemShape->AdditionalProperties->ExtendedFieldURI[] = new PathToExtendedFieldType(
			'PublicStrings',
			null,
			null,
			'DAV:uid',
			null,
			'String'
		);
		/*
		$request->ItemShape->AdditionalProperties->ExtendedFieldURI[] = new \OCA\EWS\Components\EWS\Type\PathToExtendedFieldType(
			null,
			null,
			null,
			null,
			'0x3007',
			'SystemTime'
		);
		$request->ItemShape->AdditionalProperties->ExtendedFieldURI[] = new \OCA\EWS\Components\EWS\Type\PathToExtendedFieldType(
			null,
			null,
			null,
			null,
			'0x3008',
			'SystemTime'
		);
		*/
		// define paging
		$request->IndexedPageItemView = new IndexedPageViewType('Beginning', 0, 512);
		// define criteria
		$request->Restriction = new RestrictionType();
		$request->Restriction->IsEqualTo = new IsEqualToType();
		$request->Restriction->IsEqualTo->ExtendedFieldURI = new PathToExtendedFieldType(
			'PublicStrings',
			null,
			null,
			'DAV:uid',
			null,
			'String'
		);
		$request->Restriction->IsEqualTo->FieldURIOrConstant = new FieldURIOrConstantType();
		$request->Restriction->IsEqualTo->FieldURIOrConstant->Constant = new ConstantValueType($uuid);
		// execute request
		$response = $DataStore->FindItem($request);
		// process response
		$response = $response->ResponseMessages->FindItemResponseMessage;
		$data = null;
		foreach ($response as $response_data) {
			// check response for failure
			if ($response_data->ResponseClass != ResponseClassType::SUCCESS) {
				$code = $response_data->ResponseCode;
				$message = $response_data->MessageText;
				continue;
			} else {
				if (isset($response_data->RootFolder->Items)) {
					$data = $response_data->RootFolder->Items;
				}
			}
		}
		// return object or null
		return $data;

	}

	/**
     * retrieve all information for specific item from remote storage
     *
     * @since Release 1.0.0
     *
	 * @param EWSClient $DataStore
	 * 		Storage Interface
	 * @param array $ioc
	 * 		Collection of Id Objects
	 * @param string $base
	 * 		Base Properties to return ( D - Default | A - All | I - ID Only )
	 * @param object $additional
	 * 		Additional Properties to return ( Object of NonEmptyArrayOfPathsToElementType )
	 * @return object
	 * 		Item Object on success / Null on failure
	 */
	public function fetchItem(EWSClient $DataStore, array $ioc, string $base = 'D', object $additional = null): ?object {

		// construct request
		$request = new GetItemType();
		// define target
		$request->ItemIds = new NonEmptyArrayOfBaseItemIdsType();
		$request->ItemIds->ItemId = $ioc;
		// define required base properties
		$request->ItemShape = new ItemResponseShapeType();
		if ($base == 'A') {
			$request->ItemShape->BaseShape = self::SCOPE_ATTRIBUTES_ENTIRE;
		}
		elseif ($base == 'I') {
			$request->ItemShape->BaseShape = self::SCOPE_ATTRIBUTES_BASIC;
		}
		else  {
			$request->ItemShape->BaseShape = self::SCOPE_ATTRIBUTES_PRESET;
		}
		// define required additional properties
		if ($additional instanceof NonEmptyArrayOfPathsToElementType) {
			$request->ItemShape->AdditionalProperties = $additional;
		}
		/*
		else {
			$request->ItemShape->AdditionalProperties = new \OCA\EWS\Components\EWS\ArrayType\NonEmptyArrayOfPathsToElementType();
		}
		// define required essential properties
		$request->ItemShape->AdditionalProperties->ExtendedFieldURI[] = new \OCA\EWS\Components\EWS\Type\PathToExtendedFieldType(
			'PublicStrings',
			null,
			null,
			'DAV:id',
			null,
			'String'
		);
		$request->ItemShape->AdditionalProperties->ExtendedFieldURI[] = new \OCA\EWS\Components\EWS\Type\PathToExtendedFieldType(
			'PublicStrings',
			null,
			null,
			'DAV:uid',
			null,
			'String'
		);
		$request->ItemShape->AdditionalProperties->ExtendedFieldURI[] = new \OCA\EWS\Components\EWS\Type\PathToExtendedFieldType(
			null,
			null,
			null,
			null,
			'0x3007',
			'SystemTime'
		);
		$request->ItemShape->AdditionalProperties->ExtendedFieldURI[] = new \OCA\EWS\Components\EWS\Type\PathToExtendedFieldType(
			null,
			null,
			null,
			null,
			'0x3008',
			'SystemTime'
		);
		*/
		// execute request
		$response = $DataStore->GetItem($request);
		// process response
		$response = $response->ResponseMessages->GetItemResponseMessage;
		$data = null;
		foreach ($response as $response_data) {
			// evaluate if response contained a error
			if ($response_data->ResponseClass == ResponseClassType::ERROR) {
				throw new Exception(self::DESCRIPTOR_REMOTE_ERROR . $response_data->ResponseCode . ' - ' . $response_data->MessageText);
			}
			// evaluate if response contained a warning
			elseif ($response_data->ResponseClass == ResponseClassType::WARNING) {
				$this->logger->warning(self::DESCRIPTOR_REMOTE_WARNING . $response_data->MessageText);
			}
			// extract data object from response
			$data = $response_data->Items;
		}
		// return object or null
		return $data;

	}

	/**
     * create item in remote storage
     *
     * @since Release 1.0.0
     *
	 * @param EWSClient $DataStore
	 * 		Storage Interface
	 * @param string $fid
	 * 		Folder ID
	 *
	 * @return object
	 * 		Attachement Collection Object on success / Null on failure
	 */
	public function createItem(EWSClient $DataStore, string $fid, object $data): ?object {

		// construct request
		$request = new CreateItemType();
		$request->SendMeetingInvitations = 'SendToNone';
		// define target
		$request->SavedItemFolderId = new TargetFolderIdType();
		$request->SavedItemFolderId->FolderId = new FolderIdType($fid);
		// define objects to create
		$request->Items = new NonEmptyArrayOfAllItemsType();
		if (is_a($data, 'OCA\EWS\Components\EWS\Type\ContactItemType')) {
			$request->Items->Contact[] = $data;
		}
		elseif (is_a($data, 'OCA\EWS\Components\EWS\Type\CalendarItemType')) {
			$request->Items->CalendarItem[] = $data;
		}
		elseif (is_a($data, 'OCA\EWS\Components\EWS\Type\TaskType')) {
			$request->Items->Task[] = $data;
		}
		// execute request
		$response = $DataStore->CreateItem($request);
		// process response
		$response = $response->ResponseMessages->CreateItemResponseMessage;
		$data = null;
		foreach ($response as $response_data) {
			// evaluate if response contained a error
			if ($response_data->ResponseClass == ResponseClassType::ERROR) {
				throw new Exception(self::DESCRIPTOR_REMOTE_ERROR . $response_data->ResponseCode . ' - ' . $response_data->MessageText);
			}
			// evaluate if response contained a warning
			elseif ($response_data->ResponseClass == ResponseClassType::WARNING) {
				$this->logger->warning(self::DESCRIPTOR_REMOTE_WARNING . $response_data->MessageText);
			}
			// extract data object from response
			$data = $response_data->Items;
		}
		// return object or null
		return $data;

	}

	/**
     * update item in remote storage
     *
     * @since Release 1.0.0
     *
	 * @param EWSClient $DataStore
	 * 		Storage Interface
	 * @param string $fid
	 * 		Folder ID
	 * @param string $iid
	 * 		Item ID
	 * @param string $istate
	 * 		Item State
	 * @param string $a
	 * 		Item Append Commands
	 * @param string $u
	 * 		Item Update Commands
	 * @param string $d
	 * 		Item Delete Commands
	 * @return object
	 * 		Items Array on success / Null on failure
	 */
	public function updateItem(EWSClient $DataStore, string $fid, string $iid, string $istate = null, array $additions = null, array $modifications = null, array $deletions = null): ?object {

		// construct request
		$request = new UpdateItemType();
		$request->ConflictResolution = 'AlwaysOverwrite';
		$request->SendMeetingInvitationsOrCancellations = 'SendToNone';
		// define target folder
		$request->SavedItemFolderId = new TargetFolderIdType();
		$request->SavedItemFolderId->FolderId = new FolderIdType($fid);
		// define target object and changes
		$request->ItemChanges[] = new ItemChangeType(
			new ItemIdType($iid, $istate),
			new NonEmptyArrayOfItemChangeDescriptionsType($additions, $modifications, $deletions)
		);
		// execute request
		$response = $DataStore->UpdateItem($request);
		// process response
		$response = $response->ResponseMessages->UpdateItemResponseMessage;
		$data = null;
		foreach ($response as $response_data) {
			// evaluate if response contained a error
			if ($response_data->ResponseClass == ResponseClassType::ERROR) {
				throw new Exception(self::DESCRIPTOR_REMOTE_ERROR . $response_data->ResponseCode . ' - ' . $response_data->MessageText);
			}
			// evaluate if response contained a warning
			elseif ($response_data->ResponseClass == ResponseClassType::WARNING) {
				$this->logger->warning(self::DESCRIPTOR_REMOTE_WARNING . $response_data->MessageText);
			}
			// extract data object from response
			$data = $response_data->Items;
		}
		// return object or null
		return $data;

	}

	/**
     * delete item in remote storage
     *
     * @since Release 1.0.0
     *
	 * @param EWSClient $DataStore - Storage Interface
	 * @param string $ids
	 * 		Item ID's Array
	 * @param string $fid
	 * 		Item Data
	 * @return object
	 * 		Attachement Collection Object on success / Null on failure
	 */
	public function deleteItem(EWSClient $DataStore, array $ids = null, string $type = 'SoftDelete', array $options = []): ?bool {

		// construct request
		$request = new DeleteItemType();
		$request->SendMeetingCancellations = 'SendToNone';
		if (isset($options['TaskOccurrences'])) {
			$request->AffectedTaskOccurrences = $options['TaskOccurrences'];
		}
		$request->DeleteType = $type;
		// define objects to delete
		$request->ItemIds = new NonEmptyArrayOfBaseItemIdsType($ids);
		// execute request
		$response = $DataStore->DeleteItem($request);
		// process response
		$response = $response->ResponseMessages->DeleteItemResponseMessage;
		$data = null;
		foreach ($response as $response_data) {
			// evaluate if response contained a error
			if ($response_data->ResponseClass == ResponseClassType::ERROR) {
				throw new Exception(self::DESCRIPTOR_REMOTE_ERROR . $response_data->ResponseCode . ' - ' . $response_data->MessageText);
			}
			// evaluate if response contained a warning
			elseif ($response_data->ResponseClass == ResponseClassType::WARNING) {
				$this->logger->warning(self::DESCRIPTOR_REMOTE_WARNING . $response_data->MessageText);
			}
			// extract data object from response
			$data = true;
		}
		// return object or null
		return $data;

	}

	/**
     * retrieve item attachment(s) from remote storage
     *
     * @since Release 1.0.0
     *
	 * @param EWSClient $DataStore
	 * 		Storage Interface
	 * @param string $ids
	 * 		Attachement ID's (array)
	 * @return object
	 * 		Attachement Collection Object on success / Null on failure
	 */
	public function fetchAttachment(EWSClient $DataStore, array $batch): ?array {

		// construct request
		$request = new GetAttachmentType();
		// define target(s)
		$request->AttachmentIds = new NonEmptyArrayOfRequestAttachmentIdsType();
		foreach ($batch as $entry) {
			$request->AttachmentIds->AttachmentId[] = new RequestAttachmentIdType((String) $entry);
		}
		// execute request
		$response = $DataStore->GetAttachment($request);
		// process response
		$response = $response->ResponseMessages->GetAttachmentResponseMessage;
		$data = array();
		foreach ($response as $response_data) {
			// evaluate if response contained a error
			if ($response_data->ResponseClass == ResponseClassType::ERROR) {
				throw new Exception(self::DESCRIPTOR_REMOTE_ERROR . $response_data->ResponseCode . ' - ' . $response_data->MessageText);
			}
			// evaluate if response contained a warning
			elseif ($response_data->ResponseClass == ResponseClassType::WARNING) {
				$this->logger->warning(self::DESCRIPTOR_REMOTE_WARNING . $response_data->MessageText);
			}
			// extract data object from response
			$data = array_merge($data, (array) $response_data->Attachments->FileAttachment, (array) $response_data->Attachments->ItemAttachment);
		}
		// return object or null
		return $data;

	}

	/**
     * create item attachment(s) from remote storage
     *
     * @since Release 1.0.0
     *
	 * @param EWSClient $DataStore
	 * 		Storage Interface
	 * @param array $batch
	 * 		Collection of FileAttachmentType Objects
	 * @return object
	 * 		Attachement Collection Object on success / Null on failure
	 */
	public function createAttachment(EWSClient $DataStore, string $iid, array $batch): array {

		// construct request
		$request = new CreateAttachmentType();
		// define target
		$request->ParentItemId = new ItemIdType($iid);
		// define objects to create
		$request->Attachments = new NonEmptyArrayOfAttachmentsType();
		foreach ($batch as $entry) {
			if (is_a($entry, 'OCA\EWS\Components\EWS\Type\FileAttachmentType')) {
				$request->Attachments->FileAttachment[] = $entry;
			}
		}
		// execute request
		$response = $DataStore->CreateAttachment($request);
		// process response
		$response = $response->ResponseMessages->CreateAttachmentResponseMessage;
		$data = array();
		foreach ($response as $response_data) {
			// evaluate if response contained a error
			if ($response_data->ResponseClass == ResponseClassType::ERROR) {
				throw new Exception(self::DESCRIPTOR_REMOTE_ERROR . $response_data->ResponseCode . ' - ' . $response_data->MessageText);
			}
			// evaluate if response contained a warning
			elseif ($response_data->ResponseClass == ResponseClassType::WARNING) {
				$this->logger->warning(self::DESCRIPTOR_REMOTE_WARNING . $response_data->MessageText);
			}
			// extract data object from response
			$data = array_merge($data, (array) $response_data->Attachments->FileAttachment, (array) $response_data->Attachments->ItemAttachment);
		}
		// return object or null
		return $data;

	}

	/**
     * delete item attachment(s) from remote storage
     *
     * @since Release 1.0.0
     *
	 * @param EWSClient $DataStore
	 * 		Storage Interface
	 * @param array $batch
	 * 		Collection of String Attachemnt Id(s)
	 * @return object
	 * 		Attachement Collection Object on success / Null on failure
	 */
	public function deleteAttachment(EWSClient $DataStore, array $batch): array {

		// construct request
		$request = new DeleteAttachmentType();
		// define target(s) to delete
		$request->AttachmentIds = new NonEmptyArrayOfRequestAttachmentIdsType();
		foreach ($batch as $entry) {
			$request->AttachmentIds->AttachmentId[] = new RequestAttachmentIdType((String) $entry);
		}
		// execute request
		$response = $DataStore->DeleteAttachment($request);
		// process response
		$response = $response->ResponseMessages->DeleteAttachmentResponseMessage;
		// construct result collection
		$data = array();
		foreach ($response as $key => $entry) {
			// make sure the request succeeded.
			if ($entry->ResponseClass != ResponseClassType::SUCCESS) {
				$data[] = array('Id' => $batch[$key], 'Status' => false, 'reason' => $entry->MessageText);
			} else {
				$data[] = array('Id' => $batch[$key], 'Status' => true);
			}
		}
		// return result collection
		return $data;

	}

	/**
     * retrieve time zone information from remote storage
     *
     * @since Release 1.0.0
     *
	 * @param EWSClient $DataStore
	 * 		Storage Interface
	 * @param string $zone
	 * 		Time Zone Name or Blank
	 * @return object
	 * 		Item Object on success / Null on failure
	 */
	public function fetchTimeZone(EWSClient $DataStore, string $zone = null): ?object {

		// construct request
		$request = new GetServerTimeZonesType();
		// define target
		if (!empty($zone)) {
			$request->Ids = new NonEmptyArrayOfTimeZoneIdType();
			$request->Ids->Id[] = $zone;
		}
		// execute request
		$response = $DataStore->GetServerTimeZones($request);
		// process response
		$response = $response->ResponseMessages->GetServerTimeZonesResponseMessage;
		$data = null;
		foreach ($response as $response_data) {
			// evaluate if response contained a error
			if ($response_data->ResponseClass == ResponseClassType::ERROR) {
				throw new Exception(self::DESCRIPTOR_REMOTE_ERROR . $response_data->ResponseCode . ' - ' . $response_data->MessageText);
			}
			// evaluate if response contained a warning
			elseif ($response_data->ResponseClass == ResponseClassType::WARNING) {
				$this->logger->warning(self::DESCRIPTOR_REMOTE_WARNING . $response_data->MessageText);
			}
			// extract data object from response
			$data = $response_data->TimeZoneDefinitions;
		}
		// return object or null
		return $data;

	}

	/**
     * connect to event nofifications
     *
     * @since Release 1.0.0
     *
	 * @param EWSClient $DataStore
	 * 		Storage Interface
	 * @return object
	 * 		Items Object on success / Null on failure
	 */
	public function connectEvents(EWSClient $DataStore, int $duration, array $ids = null, array $dids = null, array $types = null): ?object {

		// construct request
		$request = new SubscribeType();
		$request->PullSubscriptionRequest = new PullSubscriptionRequestType();
		$request->PullSubscriptionRequest->FolderIds = new NonEmptyArrayOfBaseFolderIdsType();
		$request->PullSubscriptionRequest->EventTypes = new NonEmptyArrayOfNotificationEventTypesType();
		$request->PullSubscriptionRequest->Timeout = $duration;
		// define target(s)
		if (isset($ids)) {
			foreach ($ids as $entry) {
				$request->PullSubscriptionRequest->FolderIds->FolderId[] = new FolderIdType($entry);
			}
		}
		if (isset($dids)) {
			foreach ($dids as $entry) {
				$request->PullSubscriptionRequest->FolderIds->DistinguishedFolderId[] = new DistinguishedFolderIdType($entry);
			}
		}
		// define types(s)
		if (isset($types)) {
			$request->PullSubscriptionRequest->EventTypes->EventType = $types;
		}
		else {
			$request->PullSubscriptionRequest->EventTypes->EventType = ['CreatedEvent', 'ModifiedEvent', 'DeletedEvent', 'CopiedEvent', 'MovedEvent'];
		}
		// execute request
		$response = $DataStore->Subscribe($request);
		// process response
		$response = $response->ResponseMessages->SubscribeResponseMessage;
		$data = null;
		foreach ($response as $response_data) {
			// evaluate if response contained a error
			if ($response_data->ResponseClass == ResponseClassType::ERROR) {
				throw new Exception(self::DESCRIPTOR_REMOTE_ERROR . $response_data->ResponseCode . ' - ' . $response_data->MessageText);
			}
			// evaluate if response contained a warning
			elseif ($response_data->ResponseClass == ResponseClassType::WARNING) {
				$this->logger->warning(self::DESCRIPTOR_REMOTE_WARNING . $response_data->MessageText);
			}
			// extract data object from response
			$data = (object) ['Id' => $response_data->SubscriptionId, 'Token' => $response_data->Watermark];
		}
		// return object or null
		return $data;

	}

	/**
     * disconnect from event nofifications
     *
     * @since Release 1.0.0
     *
	 * @param EWSClient $DataStore
	 * 		Storage Interface
	 * @return object
	 * 		Items Object on success / Null on failure
	 */
	public function disconnectEvents(EWSClient $DataStore, string $id): ?bool {

		// construct request
		$request = new UnsubscribeType();
		$request->SubscriptionId = $id;
		// execute request
		$response = $DataStore->Unsubscribe($request);
		// process response
		$response = $response->ResponseMessages->UnsubscribeResponseMessage;
		$data = null;
		foreach ($response as $response_data) {
			// evaluate if response contained a error
			if ($response_data->ResponseClass == ResponseClassType::ERROR) {
				throw new Exception(self::DESCRIPTOR_REMOTE_ERROR . $response_data->ResponseCode . ' - ' . $response_data->MessageText);
			}
			// evaluate if response contained a warning
			elseif ($response_data->ResponseClass == ResponseClassType::WARNING) {
				$this->logger->warning(self::DESCRIPTOR_REMOTE_WARNING . $response_data->MessageText);
			}
			// extract data object from response
			$data = true;
		}
		// return object or null
		return $data;

	}

	/**
     * observe event nofifications
     *
     * @since Release 1.0.0
     *
	 * @param EWSClient $DataStore
	 * 		Storage Interface
	 * @return object
	 * 		Items Object on success / Null on failure
	 */
	public function fetchEvents(EWSClient $DataStore, string $id, string $token): ?object {

		// construct request
		$request = new GetEventsType();
		$request->SubscriptionId = $id;
		$request->Watermark = $token;
		// execute request
		$response = $DataStore->GetEvents($request);
		// process response
		$response = $response->ResponseMessages->GetEventsResponseMessage;
		$data = null;
		foreach ($response as $response_data) {
			// evaluate if response contained a error
			if ($response_data->ResponseClass == ResponseClassType::ERROR) {
				throw new Exception(self::DESCRIPTOR_REMOTE_ERROR . $response_data->ResponseCode . ' - ' . $response_data->MessageText);
			}
			// evaluate if response contained a warning
			elseif ($response_data->ResponseClass == ResponseClassType::WARNING) {
				$this->logger->warning(self::DESCRIPTOR_REMOTE_WARNING . $response_data->MessageText);
			}
			// extract data object from response
			$data = $response_data->Notification;
		}
		// return object or null
		return $data;

	}

}
