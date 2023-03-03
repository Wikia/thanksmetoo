<?php
/**
 * API module to send thanks notifications for revisions and log entries.
 *
 * @ingroup API
 * @ingroup Extensions
 */

namespace ThanksMeToo;

use ActorMigration;
use ApiBase;
use DatabaseLogEntry;
use LogEntry;
use ManualLogEntry;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionRecord;
use Reverb\Notification\NotificationBroadcast;
use Title;
use User;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\NumericDef;

class ApiCoreThank extends ApiBase {
	/**
	 * Perform the API request.
	 */
	public function execute() {
		// Initial setup.
		$user = $this->getUser();
		$this->dieOnBadUser( $user );
		$params = $this->extractRequestParams();
		$revcreation = false;

		$this->requireOnlyOneParameter( $params, 'rev', 'log' );

		// Extract type and ID from the parameters.
		if ( isset( $params['rev'] ) && !isset( $params['log'] ) ) {
			$type = 'rev';
			$id = $params['rev'];
		} elseif ( !isset( $params['rev'] ) && isset( $params['log'] ) ) {
			$type = 'log';
			$id = $params['log'];
		} else {
			$this->dieWithError( 'thanks-error-api-params', 'thanks-error-api-params' );
		}

		// Determine thanks parameters.
		if ( $type === 'log' ) {
			$logEntry = $this->getLogEntryFromId( $id );
			// If there's an associated revision, thank for that instead.
			if ( $logEntry->getAssociatedRevId() ) {
				$type = 'rev';
				$id = $logEntry->getAssociatedRevId();
			} else {
				$excerpt = '';
				$title = $logEntry->getTarget();
				$recipient = $this->getUserFromLog( $logEntry );
				$recipientUsername = $recipient->getName();
			}
		}
		if ( $type === 'rev' ) {
			$revision = $this->getRevisionFromId( $id );
			$title = $this->getTitleFromRevision( $revision );
			$recipient = $this->getUserFromRevision( $revision );

			// If there is no parent revid of this revision, it's a page creation.
			$previousRevision = MediaWikiServices::getInstance()
				->getRevisionLookup()->getPreviousRevision( $revision );

			if ( !$previousRevision ) {
				$revcreation = true;
			}
		}

		// Send thanks.
		if ( $this->userAlreadySentThanks( $user, $type, $id ) ) {
			$this->markResultSuccess( $recipientUsername->getName() );
		} else {
			$this->dieOnBadRecipient( $user, $recipient );
			$this->sendThanks(
				$user,
				$type,
				$id,
				'',
				$recipient,
				$this->getSourceFromParams( $params ),
				$title,
				$revcreation
			);
		}
	}

	/**
	 * Check the session data for an indication of whether this user has already sent this thanks.
	 *
	 * @param User $user The user being thanked.
	 * @param string $type Either 'rev' or 'log'.
	 * @param int $id The revision or log ID.
	 * @return bool
	 */
	private function userAlreadySentThanks( User $user, $type, $id ) {
		if ( $type === 'rev' ) {
			// For b/c with old-style keys
			$type = '';
		}
		return (bool)$user->getRequest()->getSessionData( "thanks-thanked-$type$id" );
	}

	private function getRevisionFromId( $revId ) {
		$revision = MediaWikiServices::getInstance()
			->getRevisionStore()
			->getRevisionById( $revId );
		// Revision ID 1 means an invalid argument was passed in.
		if ( !$revision || $revision->getId() === 1 ) {
			$this->dieWithError( 'thanks-error-invalidrevision', 'invalidrevision' );
		} elseif ( $revision->isDeleted( RevisionRecord::DELETED_TEXT ) ) {
			$this->dieWithError( 'thanks-error-revdeleted', 'revdeleted' );
		}
		return $revision;
	}

	/**
	 * Get the log entry from the ID.
	 *
	 * @param int $logId The log entry ID.
	 * @return DatabaseLogEntry
	 */
	private function getLogEntryFromId( $logId ) {
		$logEntry = DatabaseLogEntry::newFromId( $logId, wfGetDB( DB_REPLICA ) );

		if ( !$logEntry ) {
			$this->dieWithError( 'thanks-error-invalid-log-id', 'thanks-error-invalid-log-id' );
		}

		// Make sure this log type is whitelisted.
		$logTypeWhitelist = $this->getConfig()->get( 'ThanksLogTypeWhitelist' );
		if ( !in_array( $logEntry->getType(), $logTypeWhitelist ) ) {
			$err = $this->msg( 'thanks-error-invalid-log-type', $logEntry->getType() );
			$this->dieWithError( $err, 'thanks-error-invalid-log-type' );
		}

		// Don't permit thanks if any part of the log entry is deleted.
		if ( $logEntry->getDeleted() ) {
			$this->dieWithError( 'thanks-error-log-deleted', 'thanks-error-log-deleted' );
		}

		return $logEntry;
	}

	private function getTitleFromRevision( RevisionRecord $revision ) {
		$title = Title::newFromID( $revision->getPage()->getId() );
		if ( !$title instanceof Title ) {
			$this->dieWithError( 'thanks-error-notitle', 'notitle' );
		}
		return $title;
	}

	/**
	 * Set the source of the thanks, e.g. 'diff' or 'history'
	 *
	 * @param string[] $params Incoming API parameters, with a 'source' key.
	 * @return string The source, or 'undefined' if not provided.
	 */
	private function getSourceFromParams( $params ) {
		if ( $params['source'] ) {
			return trim( $params['source'] );
		} else {
			return 'undefined';
		}
	}

	private function getUserFromRevision( RevisionRecord $revision ) {
		$recipient = $revision->getUser();
		if ( !$recipient ) {
			$this->dieWithError( 'thanks-error-invalidrecipient', 'invalidrecipient' );
		}
		return MediaWikiServices::getInstance()
			->getUserFactory()
			->newFromId( $recipient->getId() );
	}

	private function getUserFromLog( LogEntry $logEntry ) {
		$recipient = $logEntry->getPerformer();
		if ( !$recipient ) {
			$this->dieWithError( 'thanks-error-invalidrecipient', 'invalidrecipient' );
		}
		return $recipient;
	}

	/**
	 * Create the thanks notification event, and log the thanks.
	 *
	 * @param User $agent The thanks-sending user.
	 * @param string $type The thanks type ('rev' or 'log').
	 * @param int $id The log or revision ID.
	 * @param string $excerpt The excerpt to display as the thanks notification. This will only
	 *                             be used if it is not possible to retrieve the relevant excerpt at
	 *                             the time the notification is displayed (in order to account for
	 *                             changing visibility in the meantime).
	 * @param User $recipient The recipient of the thanks.
	 * @param string $source Where the thanks was given.
	 * @param Title $title The title of the page for which thanks is given.
	 * @param bool $revcreation True if the linked revision is a page creation.
	 *
	 * @return void
	 */
	private function sendThanks(
		User $agent, $type, $id, $excerpt, User $recipient, $source, Title $title, $revcreation
	) {
		$uniqueId = $type . '-' . $id;
		// Do one last check to make sure we haven't sent Thanks before
		if ( $this->haveAlreadyThanked( $agent, $uniqueId ) ) {
			// Pretend the thanks were sent
			$this->markResultSuccess( $recipient->getName() );
			return;
		}

		$agentUserTitle = Title::makeTitle( NS_USER_PROFILE, $agent->getName() );
		$recipientUserTitle = Title::makeTitle( NS_USER_PROFILE, $recipient->getName() );
		$broadcast = NotificationBroadcast::newSingle(
			'user-interest-thanks-' . ( $revcreation ? 'creation' : 'edit' ),
			$agent,
			$recipient,
			[
				'url' => $title->getFullUrl(),
				'message' => [
					[
						'user_note',
						''
					],
					[
						1,
						$agentUserTitle->getFullUrl()
					],
					[
						2,
						$agent->getName()
					],
					[
						3,
						$recipientUserTitle->getFullURL()
					],
					[
						4,
						$recipient->getName()
					],
					[
						5,
						$title->getFullURL()
					],
					[
						6,
						$title->getFullText()
					]
				]
			]
		);
		if ( $broadcast ) {
			$broadcast->transmit();
		}

		// And mark the thank in session for a cheaper check to prevent duplicates (Phab:T48690).
		$agent->getRequest()->setSessionData( "thanks-thanked-$type$id", true );
		// Set success message
		$this->markResultSuccess( $recipient->getName() );
		$this->logThanks( $agent, $recipient, $uniqueId );
	}

	public function getAllowedParams() {
		return [
			'rev' => [
				ParamValidator::PARAM_TYPE => 'integer',
				NumericDef::PARAM_MIN => 1,
				ParamValidator::PARAM_REQUIRED => false,
			],
			'log' => [
				ParamValidator::PARAM_TYPE => 'integer',
				NumericDef::PARAM_MIN => 1,
				ParamValidator::PARAM_REQUIRED => false,
			],
			'token' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'source' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
			]
		];
	}

	private function dieOnBadUser( User $user ) {
		$userBlock = $user->getBlock();
		if ( $user->isAnon() ) {
			$this->dieWithError( 'thanks-error-notloggedin', 'notloggedin' );
		} elseif ( $user->pingLimiter( 'thanks-notification' ) ) {
			$this->dieWithError( [ 'thanks-error-ratelimited', $user->getName() ], 'ratelimited' );
		} elseif ( $userBlock && $userBlock->isSitewide() ) {
			$this->dieBlocked( $userBlock );
		} elseif ( $user->isBlockedGlobally() ) {
			$this->dieBlocked( $user->getGlobalBlock() );
		}
	}

	private function dieOnBadRecipient( User $user, User $recipient ) {
		if ( $user->getId() === $recipient->getId() ) {
			$this->dieWithError( 'thanks-error-invalidrecipient-self', 'invalidrecipient' );
		} elseif ( !$this->getConfig()->get( 'ThanksSendToBots' ) && $recipient->isBot() ) {
			$this->dieWithError( 'thanks-error-invalidrecipient-bot', 'invalidrecipient' );
		}
	}

	private function markResultSuccess( $recipientName ) {
		$this->getResult()->addValue( null, 'result', [
			'success' => 1,
			'recipient' => $recipientName,
		] );
	}

	/**
	 * This checks the log_search data.
	 *
	 * @param User $thanker The user sending the thanks.
	 * @param string $uniqueId The identifier for the thanks.
	 * @return bool Whether thanks has already been sent
	 */
	private function haveAlreadyThanked( User $thanker, $uniqueId ) {
		$dbw = wfGetDB( DB_PRIMARY );
		$logWhere = ActorMigration::newMigration()->getWhere( $dbw, 'log_user', $thanker );
		return (bool)$dbw->selectRow(
			[ 'log_search', 'logging' ] + $logWhere['tables'],
			[ 'ls_value' ],
			[
				$logWhere['conds'],
				'ls_field' => 'thankid',
				'ls_value' => $uniqueId,
			],
			__METHOD__,
			[],
			[ 'logging' => [ 'INNER JOIN', 'ls_log_id=log_id' ] ] + $logWhere['joins']
		);
	}

	/**
	 * @param User $user The user performing the thanks (and the log entry).
	 * @param User $recipient The target of the thanks (and the log entry).
	 * @param string $uniqueId A unique Id to identify the event being thanked for, to use
	 *                          when checking for duplicate thanks
	 */
	private function logThanks( User $user, User $recipient, $uniqueId ) {
		if ( !$this->getConfig()->get( 'ThanksLogging' ) ) {
			return;
		}
		$logEntry = new ManualLogEntry( 'thanks', 'thank' );
		$logEntry->setPerformer( $user );
		$logEntry->setRelations( [ 'thankid' => $uniqueId ] );
		$target = $recipient->getUserPage();
		$logEntry->setTarget( $target );
		$logId = $logEntry->insert();
		$logEntry->publish( $logId, 'udp' );
	}

	public function needsToken() {
		return 'csrf';
	}

	public function isWriteMode() {
		// Writes to the Echo database and sometimes log tables.
		return true;
	}

	public function getHelpUrls() {
		return [
			'https://www.mediawiki.org/wiki/Extension:Thanks#API_Documentation',
		];
	}

	/**
	 * @see    ApiBase::getExamplesMessages()
	 * @return array
	 */
	protected function getExamplesMessages() {
		return [
			'action=thank&revid=456&source=diff&token=123ABC'
				=> 'apihelp-thank-example-1',
		];
	}
}
