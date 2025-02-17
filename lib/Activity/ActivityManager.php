<?php
/**
 * @copyright Copyright (c) 2018 Julius Härtl <jus@bitgrid.net>
 *
 * @copyright Copyright (c) 2019 Alexandru Puiu <alexpuiu20@yahoo.com>
 *
 * @author Julius Härtl <jus@bitgrid.net>
 *
 * @license GNU AGPL version 3 or any later version
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
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Deck\Activity;

use InvalidArgumentException;
use OCA\Deck\Db\Acl;
use OCA\Deck\Db\AclMapper;
use OCA\Deck\Db\AssignedUsers;
use OCA\Deck\Db\Attachment;
use OCA\Deck\Db\AttachmentMapper;
use OCA\Deck\Db\Board;
use OCA\Deck\Db\BoardMapper;
use OCA\Deck\Db\Card;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\Label;
use OCA\Deck\Db\Stack;
use OCA\Deck\Db\StackMapper;
use OCA\Deck\Service\PermissionService;
use OCP\Activity\IEvent;
use OCP\Activity\IManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\Comments\IComment;
use OCP\IL10N;
use OCP\IUser;

class ActivityManager {
	public const DECK_NOAUTHOR_COMMENT_SYSTEM_ENFORCED = 'DECK_NOAUTHOR_COMMENT_SYSTEM_ENFORCED';
	private $manager;
	private $userId;
	private $permissionService;
	private $boardMapper;
	private $cardMapper;
	private $attachmentMapper;
	private $aclMapper;
	private $stackMapper;
	private $l10n;

	public const DECK_OBJECT_BOARD = 'deck_board';
	public const DECK_OBJECT_CARD = 'deck_card';

	public const SUBJECT_BOARD_CREATE = 'board_create';
	public const SUBJECT_BOARD_UPDATE = 'board_update';
	public const SUBJECT_BOARD_UPDATE_TITLE = 'board_update_title';
	public const SUBJECT_BOARD_UPDATE_ARCHIVED = 'board_update_archived';
	public const SUBJECT_BOARD_DELETE = 'board_delete';
	public const SUBJECT_BOARD_RESTORE = 'board_restore';
	public const SUBJECT_BOARD_SHARE = 'board_share';
	public const SUBJECT_BOARD_UNSHARE = 'board_unshare';

	public const SUBJECT_STACK_CREATE = 'stack_create';
	public const SUBJECT_STACK_UPDATE = 'stack_update';
	public const SUBJECT_STACK_UPDATE_TITLE = 'stack_update_title';
	public const SUBJECT_STACK_UPDATE_ORDER = 'stack_update_order';
	public const SUBJECT_STACK_DELETE = 'stack_delete';

	public const SUBJECT_CARD_CREATE = 'card_create';
	public const SUBJECT_CARD_DELETE = 'card_delete';
	public const SUBJECT_CARD_RESTORE = 'card_restore';
	public const SUBJECT_CARD_UPDATE = 'card_update';
	public const SUBJECT_CARD_UPDATE_TITLE = 'card_update_title';
	public const SUBJECT_CARD_UPDATE_DESCRIPTION = 'card_update_description';
	public const SUBJECT_CARD_UPDATE_DUEDATE = 'card_update_duedate';
	public const SUBJECT_CARD_UPDATE_ARCHIVE = 'card_update_archive';
	public const SUBJECT_CARD_UPDATE_UNARCHIVE = 'card_update_unarchive';
	public const SUBJECT_CARD_UPDATE_STACKID = 'card_update_stackId';
	public const SUBJECT_CARD_USER_ASSIGN = 'card_user_assign';
	public const SUBJECT_CARD_USER_UNASSIGN = 'card_user_unassign';

	public const SUBJECT_ATTACHMENT_CREATE = 'attachment_create';
	public const SUBJECT_ATTACHMENT_UPDATE = 'attachment_update';
	public const SUBJECT_ATTACHMENT_DELETE = 'attachment_delete';
	public const SUBJECT_ATTACHMENT_RESTORE = 'attachment_restore';

	public const SUBJECT_LABEL_CREATE = 'label_create';
	public const SUBJECT_LABEL_UPDATE = 'label_update';
	public const SUBJECT_LABEL_DELETE = 'label_delete';
	public const SUBJECT_LABEL_ASSIGN = 'label_assign';
	public const SUBJECT_LABEL_UNASSING = 'label_unassign';

	public const SUBJECT_CARD_COMMENT_CREATE = 'card_comment_create';

	public function __construct(
		IManager $manager,
		PermissionService $permissionsService,
		BoardMapper $boardMapper,
		CardMapper $cardMapper,
		StackMapper $stackMapper,
		AttachmentMapper $attachmentMapper,
		AclMapper $aclMapper,
		IL10N $l10n,
		$userId
	) {
		$this->manager = $manager;
		$this->permissionService = $permissionsService;
		$this->boardMapper = $boardMapper;
		$this->cardMapper = $cardMapper;
		$this->stackMapper = $stackMapper;
		$this->attachmentMapper = $attachmentMapper;
		$this->aclMapper = $aclMapper;
		$this->l10n = $l10n;
		$this->userId = $userId;
	}

	/**
	 * @param $subjectIdentifier
	 * @param array $subjectParams
	 * @param bool $ownActivity
	 * @return string
	 */
	public function getActivityFormat($subjectIdentifier, $subjectParams = [], $ownActivity = false) {
		$subject = '';
		switch ($subjectIdentifier) {
			case self::SUBJECT_BOARD_CREATE:
				$subject = $ownActivity ? $this->l10n->t('You have created a new board {board}'): $this->l10n->t('{user} has created a new board {board}');
				break;
			case self::SUBJECT_BOARD_DELETE:
				$subject = $ownActivity ? $this->l10n->t('You have deleted the board {board}') : $this->l10n->t('{user} has deleted the board {board}');
				break;
			case self::SUBJECT_BOARD_RESTORE:
				$subject = $ownActivity ? $this->l10n->t('You have restored the board {board}') : $this->l10n->t('{user} has restored the board {board}');
				break;
			case self::SUBJECT_BOARD_SHARE:
				$subject = $ownActivity ? $this->l10n->t('You have shared the board {board} with {acl}') : $this->l10n->t('{user} has shared the board {board} with {acl}');
				break;
			case self::SUBJECT_BOARD_UNSHARE:
				$subject = $ownActivity ? $this->l10n->t('You have removed {acl} from the board {board}') : $this->l10n->t('{user} has removed {acl} from the board {board}');
				break;
			case self::SUBJECT_BOARD_UPDATE_TITLE:
				$subject = $ownActivity ? $this->l10n->t('You have renamed the board {before} to {board}') : $this->l10n->t('{user} has renamed the board {before} to {board}');
				break;
			case self::SUBJECT_BOARD_UPDATE_ARCHIVED:
				if (isset($subjectParams['after']) && $subjectParams['after']) {
					$subject = $ownActivity ? $this->l10n->t('You have archived the board {board}') : $this->l10n->t('{user} has archived the board {before}');
				} else {
					$subject = $ownActivity ? $this->l10n->t('You have unarchived the board {board}') : $this->l10n->t('{user} has unarchived the board {before}');
				}
				break;
			case self::SUBJECT_STACK_CREATE:
				$subject = $ownActivity ? $this->l10n->t('You have created a new list {stack} on board {board}') : $this->l10n->t('{user} has created a new list {stack} on board {board}');
				break;
			case self::SUBJECT_STACK_UPDATE:
				$subject = $ownActivity ? $this->l10n->t('You have created a new list {stack} on board {board}') : $this->l10n->t('{user} has created a new list {stack} on board {board}');
				break;
			case self::SUBJECT_STACK_UPDATE_TITLE:
				$subject = $ownActivity ? $this->l10n->t('You have renamed list {before} to {stack} on board {board}') : $this->l10n->t('{user} has renamed list {before} to {stack} on board {board}');
				break;
			case self::SUBJECT_STACK_DELETE:
				$subject = $ownActivity ? $this->l10n->t('You have deleted list {stack} on board {board}') : $this->l10n->t('{user} has deleted list {stack} on board {board}');
				break;
			case self::SUBJECT_CARD_CREATE:
				$subject = $ownActivity ? $this->l10n->t('You have created card {card} in list {stack} on board {board}') : $this->l10n->t('{user} has created card {card} in list {stack} on board {board}');
				break;
			case self::SUBJECT_CARD_DELETE:
				$subject = $ownActivity ? $this->l10n->t('You have deleted card {card} in list {stack} on board {board}') : $this->l10n->t('{user} has deleted card {card} in list {stack} on board {board}');
				break;
			case self::SUBJECT_CARD_UPDATE_TITLE:
				$subject = $ownActivity ? $this->l10n->t('You have renamed the card {before} to {card}') : $this->l10n->t('{user} has renamed the card {before} to {card}');
				break;
			case self::SUBJECT_CARD_UPDATE_DESCRIPTION:
				if (!isset($subjectParams['before'])) {
					$subject = $ownActivity ? $this->l10n->t('You have added a description to card {card} in list {stack} on board {board}') : $this->l10n->t('{user} has added a description to card {card} in list {stack} on board {board}');
				} else {
					$subject = $ownActivity ? $this->l10n->t('You have updated the description of card {card} in list {stack} on board {board}') : $this->l10n->t('{user} has updated the description of the card {card} in list {stack} on board {board}');
				}
				break;
			case self::SUBJECT_CARD_UPDATE_ARCHIVE:
				$subject = $ownActivity ? $this->l10n->t('You have archived card {card} in list {stack} on board {board}') : $this->l10n->t('{user} has archived card {card} in list {stack} on board {board}');
				break;
			case self::SUBJECT_CARD_UPDATE_UNARCHIVE:
				$subject = $ownActivity ? $this->l10n->t('You have unarchived card {card} in list {stack} on board {board}') : $this->l10n->t('{user} has unarchived card {card} in list {stack} on board {board}');
				break;
			case self::SUBJECT_CARD_UPDATE_DUEDATE:
				if (!isset($subjectParams['after'])) {
					$subject = $ownActivity ? $this->l10n->t('You have removed the due date of card {card}') : $this->l10n->t('{user} has removed the due date of card {card}');
				} elseif (!isset($subjectParams['before']) && isset($subjectParams['after'])) {
					$subject = $ownActivity ? $this->l10n->t('You have set the due date of card {card} to {after}') : $this->l10n->t('{user} has set the due date of card {card} to {after}');
				} else {
					$subject = $ownActivity ? $this->l10n->t('You have updated the due date of card {card} to {after}') : $this->l10n->t('{user} has updated the due date of card {card} to {after}');
				}

				break;
			case self::SUBJECT_LABEL_ASSIGN:
				$subject = $ownActivity ? $this->l10n->t('You have added the tag {label} to card {card} in list {stack} on board {board}') : $this->l10n->t('{user} has added the tag {label} to card {card} in list {stack} on board {board}');
				break;
			case self::SUBJECT_LABEL_UNASSING:
				$subject = $ownActivity ? $this->l10n->t('You have removed the tag {label} from card {card} in list {stack} on board {board}') : $this->l10n->t('{user} has removed the tag {label} from card {card} in list {stack} on board {board}');
				break;
			case self::SUBJECT_CARD_USER_ASSIGN:
				$subject = $ownActivity ? $this->l10n->t('You have assigned {assigneduser} to card {card} on board {board}') : $this->l10n->t('{user} has assigned {assigneduser} to card {card} on board {board}');
				break;
			case self::SUBJECT_CARD_USER_UNASSIGN:
				$subject = $ownActivity ? $this->l10n->t('You have unassigned {assigneduser} from card {card} on board {board}') : $this->l10n->t('{user} has unassigned {assigneduser} from card {card} on board {board}');
				break;
			case self::SUBJECT_CARD_UPDATE_STACKID:
				$subject = $ownActivity ? $this->l10n->t('You have moved the card {card} from list {stackBefore} to {stack}') : $this->l10n->t('{user} has moved the card {card} from list {stackBefore} to {stack}');
				break;
			case self::SUBJECT_ATTACHMENT_CREATE:
				$subject = $ownActivity ? $this->l10n->t('You have added the attachment {attachment} to card {card}') : $this->l10n->t('{user} has added the attachment {attachment} to card {card}');
				break;
			case self::SUBJECT_ATTACHMENT_UPDATE:
				$subject = $ownActivity ? $this->l10n->t('You have updated the attachment {attachment} on card {card}') : $this->l10n->t('{user} has updated the attachment {attachment} on card {card}');
				break;
			case self::SUBJECT_ATTACHMENT_DELETE:
				$subject = $ownActivity ? $this->l10n->t('You have deleted the attachment {attachment} from card {card}') : $this->l10n->t('{user} has deleted the attachment {attachment} from card {card}');
				break;
			case self::SUBJECT_ATTACHMENT_RESTORE:
				$subject = $ownActivity ? $this->l10n->t('You have restored the attachment {attachment} to card {card}') : $this->l10n->t('{user} has restored the attachment {attachment} to card {card}');
				break;
			case self::SUBJECT_CARD_COMMENT_CREATE:
				$subject = $ownActivity ? $this->l10n->t('You have commented on card {card}') : $this->l10n->t('{user} has commented on card {card}');
				break;
			default:
				break;
		}
		return $subject;
	}

	public function triggerEvent($objectType, $entity, $subject, $additionalParams = [], $author = null) {
		if ($author === null) {
			$author = $this->userId;
		}
		try {
			$event = $this->createEvent($objectType, $entity, $subject, $additionalParams, $author);
			if ($event !== null) {
				$json = json_encode($event->getSubjectParameters());
				if (mb_strlen($json) > 4000) {
					$params = json_decode(json_encode($event->getSubjectParameters()), true);

					$newContent = $params['after'];
					unset($params['before'], $params['after'], $params['card']['description']);

					$params['after'] = mb_substr($newContent, 0, 2000);
					if (mb_strlen($newContent) > 2000) {
						$params['after'] .= '...';
					}
					$event->setSubject($event->getSubject(), $params);
				}
				$this->sendToUsers($event);
			}
		} catch (\Exception $e) {
			// Ignore exception for undefined activities on update events
		}
	}

	/**
	 *
	 * @param $objectType
	 * @param ChangeSet $changeSet
	 * @param $subject
	 * @throws \Exception
	 */
	public function triggerUpdateEvents($objectType, ChangeSet $changeSet, $subject) {
		$previousEntity = $changeSet->getBefore();
		$entity = $changeSet->getAfter();
		$events = [];
		if ($previousEntity !== null) {
			foreach ($entity->getUpdatedFields() as $field => $value) {
				$getter = 'get' . ucfirst($field);
				$subjectComplete = $subject . '_' . $field;
				$changes = [
					'before' => $previousEntity->$getter(),
					'after' => $entity->$getter()
				];
				if ($changes['before'] !== $changes['after']) {
					try {
						$event = $this->createEvent($objectType, $entity, $subjectComplete, $changes);
						if ($event !== null) {
							$events[] = $event;
						}
					} catch (\Exception $e) {
						// Ignore exception for undefined activities on update events
					}
				}
			}
		} else {
			try {
				$events = [$this->createEvent($objectType, $entity, $subject)];
			} catch (\Exception $e) {
				// Ignore exception for undefined activities on update events
			}
		}
		foreach ($events as $event) {
			$this->sendToUsers($event);
		}
	}

	/**
	 * @param $objectType
	 * @param $entity
	 * @param $subject
	 * @param array $additionalParams
	 * @return IEvent|null
	 * @throws \Exception
	 */
	private function createEvent($objectType, $entity, $subject, $additionalParams = [], $author = null) {
		try {
			$object = $this->findObjectForEntity($objectType, $entity);
		} catch (DoesNotExistException $e) {
			\OC::$server->getLogger()->error('Could not create activity entry for ' . $subject . '. Entity not found.', (array)$entity);
			return null;
		} catch (MultipleObjectsReturnedException $e) {
			\OC::$server->getLogger()->error('Could not create activity entry for ' . $subject . '. Entity not found.', (array)$entity);
			return null;
		}

		/**
		 * Automatically fetch related details for subject parameters
		 * depending on the subject
		 */
		$eventType = 'deck';
		$subjectParams = [];
		$message = null;
		switch ($subject) {
			// No need to enhance parameters since entity already contains the required data
			case self::SUBJECT_BOARD_CREATE:
			case self::SUBJECT_BOARD_UPDATE_TITLE:
			case self::SUBJECT_BOARD_UPDATE_ARCHIVED:
			case self::SUBJECT_BOARD_DELETE:
			case self::SUBJECT_BOARD_RESTORE:
			// Not defined as there is no activity for
			// case self::SUBJECT_BOARD_UPDATE_COLOR
				break;
			case self::SUBJECT_CARD_COMMENT_CREATE:
				$eventType = 'deck_comment';
				$subjectParams = $this->findDetailsForCard($entity->getId());
				if (array_key_exists('comment', $additionalParams)) {
					/** @var IComment $entity */
					$comment = $additionalParams['comment'];
					$subjectParams['comment'] = $comment->getId();
					unset($additionalParams['comment']);
				}
				break;
			case self::SUBJECT_STACK_CREATE:
			case self::SUBJECT_STACK_UPDATE:
			case self::SUBJECT_STACK_UPDATE_TITLE:
			case self::SUBJECT_STACK_UPDATE_ORDER:
			case self::SUBJECT_STACK_DELETE:
				$subjectParams = $this->findDetailsForStack($entity->getId());
				break;

			case self::SUBJECT_CARD_CREATE:
			case self::SUBJECT_CARD_DELETE:
			case self::SUBJECT_CARD_UPDATE_ARCHIVE:
			case self::SUBJECT_CARD_UPDATE_UNARCHIVE:
			case self::SUBJECT_CARD_UPDATE_TITLE:
			case self::SUBJECT_CARD_UPDATE_DESCRIPTION:
			case self::SUBJECT_CARD_UPDATE_DUEDATE:
			case self::SUBJECT_CARD_UPDATE_STACKID:
			case self::SUBJECT_LABEL_ASSIGN:
			case self::SUBJECT_LABEL_UNASSING:
			case self::SUBJECT_CARD_USER_ASSIGN:
			case self::SUBJECT_CARD_USER_UNASSIGN:
				$subjectParams = $this->findDetailsForCard($entity->getId(), $subject);
				break;
			case self::SUBJECT_ATTACHMENT_CREATE:
			case self::SUBJECT_ATTACHMENT_UPDATE:
			case self::SUBJECT_ATTACHMENT_DELETE:
			case self::SUBJECT_ATTACHMENT_RESTORE:
				$subjectParams = $this->findDetailsForAttachment($entity->getId());
				break;
			case self::SUBJECT_BOARD_SHARE:
			case self::SUBJECT_BOARD_UNSHARE:
				$subjectParams = $this->findDetailsForAcl($entity->getId());
				break;
			default:
				throw new \Exception('Unknown subject for activity.');
				break;
		}

		if ($subject === self::SUBJECT_CARD_UPDATE_DESCRIPTION) {
			$card = $subjectParams['card'];
			if ($card->getLastEditor() === $this->userId) {
				return null;
			}
			$subjectParams['diff'] = true;
			$eventType = 'deck_card_description';
		}
		if ($subject === self::SUBJECT_CARD_UPDATE_STACKID) {
			$subjectParams['stackBefore'] = $this->stackMapper->find($additionalParams['before']);
			$subjectParams['stack'] = $this->stackMapper->find($additionalParams['after']);
		}

		$subjectParams['author'] = $this->userId;


		$event = $this->manager->generateEvent();
		$event->setApp('deck')
			->setType($eventType)
			->setAuthor($author === null ? $this->userId : $author)
			->setObject($objectType, (int)$object->getId(), $object->getTitle())
			->setSubject($subject, array_merge($subjectParams, $additionalParams))
			->setTimestamp(time());

		if ($message !== null) {
			$event->setMessage($message);
		}

		// FIXME: We currently require activities for comments even if they are disabled though settings
		// Get rid of this once the frontend fetches comments/activity individually
		if ($eventType === 'deck_comment') {
			$event->setAuthor(self::DECK_NOAUTHOR_COMMENT_SYSTEM_ENFORCED);
		}

		return $event;
	}

	/**
	 * Publish activity to all users that are part of the board of a given object
	 *
	 * @param IEvent $event
	 */
	private function sendToUsers(IEvent $event) {
		switch ($event->getObjectType()) {
			case self::DECK_OBJECT_BOARD:
				$mapper = $this->boardMapper;
				break;
			case self::DECK_OBJECT_CARD:
				$mapper = $this->cardMapper;
				break;
		}
		$boardId = $mapper->findBoardId($event->getObjectId());
		/** @var IUser $user */
		foreach ($this->permissionService->findUsers($boardId) as $user) {
			$event->setAffectedUser($user->getUID());
			/** @noinspection DisconnectedForeachInstructionInspection */
			$this->manager->publish($event);
		}
	}

	/**
	 * @param $objectType
	 * @param $entity
	 * @return null|\OCA\Deck\Db\RelationalEntity|\OCP\AppFramework\Db\Entity
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 */
	private function findObjectForEntity($objectType, $entity) {
		$className = \get_class($entity);
		if ($entity instanceof IComment) {
			$className = IComment::class;
		}
		$objectId = null;
		if ($objectType === self::DECK_OBJECT_CARD) {
			switch ($className) {
				case Card::class:
					$objectId = $entity->getId();
					break;
				case Attachment::class:
				case Label::class:
				case AssignedUsers::class:
					$objectId = $entity->getCardId();
					break;
				case IComment::class:
					$objectId = $entity->getObjectId();
					break;
				default:
					throw new InvalidArgumentException('No entity relation present for '. $className . ' to ' . $objectType);
			}
			return $this->cardMapper->find($objectId);
		}
		if ($objectType === self::DECK_OBJECT_BOARD) {
			switch ($className) {
				case Board::class:
					$objectId = $entity->getId();
					break;
				case Label::class:
				case Stack::class:
				case Acl::class:
					$objectId = $entity->getBoardId();
					break;
				default:
					throw new InvalidArgumentException('No entity relation present for '. $className . ' to ' . $objectType);
			}
			return $this->boardMapper->find($objectId);
		}
		throw new InvalidArgumentException('No entity relation present for '. $className . ' to ' . $objectType);
	}

	private function findDetailsForStack($stackId) {
		$stack = $this->stackMapper->find($stackId);
		$board = $this->boardMapper->find($stack->getBoardId());
		return [
			'stack' => $stack,
			'board' => $board
		];
	}

	private function findDetailsForCard($cardId, $subject = null) {
		$card = $this->cardMapper->find($cardId);
		$stack = $this->stackMapper->find($card->getStackId());
		$board = $this->boardMapper->find($stack->getBoardId());
		if ($subject !== self::SUBJECT_CARD_UPDATE_DESCRIPTION) {
			$card = [
				'id' => $card->getId(),
				'title' => $card->getTitle(),
				'archived' => $card->getArchived()
			];
		}
		return [
			'card' => $card,
			'stack' => $stack,
			'board' => $board
		];
	}

	private function findDetailsForAttachment($attachmentId) {
		$attachment = $this->attachmentMapper->find($attachmentId);
		$data = $this->findDetailsForCard($attachment->getCardId());
		return array_merge($data, ['attachment' => $attachment]);
	}

	private function findDetailsForAcl($aclId) {
		$acl = $this->aclMapper->find($aclId);
		$board = $this->boardMapper->find($acl->getBoardId());
		return [
			'acl' => $acl,
			'board' => $board
		];
	}
}
