<?php

namespace ThanksMeToo;

use ApiBase;
use DatabaseLogEntry;
use ManualLogEntry;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\User\UserFactory;
use Reverb\Notification\NotificationBroadcastFactory;
use Title;
use User;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\NumericDef;
use Wikimedia\Rdbms\ILoadBalancer;

class ApiCoreThank extends ApiBase {
	public function __construct(
		$query,
		$moduleName,
		private UserFactory $userFactory,
		private ILoadBalancer $loadBalancer,
		private NotificationBroadcastFactory $notificationBroadcastFactory,
		private RevisionStore $revisionStore,
		private RevisionLookup $revisionLookup
	) {
		parent::__construct( $query, $moduleName );
	}

	/** @inheritDoc */
	public function execute() {
		$user = $this->getUser();
		$this->dieOnBadUser( $user );
		$params = $this->extractRequestParams();
		$this->requireOnlyOneParameter( $params, 'rev', 'log' );

		[ $type, $id, $recipient, $title, $revcreation ] = $this->calculateParams( $params );

		// Send thanks.
		if ( $this->userAlreadySentThanks( $user, $type, $id ) ) {
			$this->markResultSuccess( $recipient->getName() );
		} else {
			$this->dieOnBadRecipient( $user, $recipient );
			$this->sendThanks(
				$user,
				$type,
				$id,
				$recipient,
				$title,
				$revcreation
			);
		}
	}

	/** @return array of [ string $type, int $id, User $recipient, Title $title, bool $revcreation ] */
	private function calculateParams( array $params ): array {
		$type = isset( $params['log'] ) ? 'log' : 'rev';
		$id = isset( $params['log'] ) ? (int)$params['log'] : (int)$params['rev'];

		// Determine thanks parameters.
		if ( $type === 'log' ) {
			$logEntry = $this->getLogEntryFromId( $id );
			// If there's an associated revision, thank for that instead.
			if ( !$logEntry->getAssociatedRevId() ) {
				$recipient = $this->userFactory->newFromUserIdentity( $logEntry->getPerformerIdentity() );
				return [ $type, $id, $recipient, $logEntry->getTarget(), false ];
			}

			// Use associated revision from this log
			$type = 'rev';
			$id = $logEntry->getAssociatedRevId();
		}

		$revision = $this->getRevisionFromId( $id );
		$title = $this->getTitleFromRevision( $revision );
		$recipient = $this->getUserFromRevision( $revision );

		// If there is no parent revid of this revision, it's a page creation.
		$revcreation = !$this->revisionLookup->getPreviousRevision( $revision );

		return [ $type, $id, $recipient, $title, $revcreation ];
	}

	/**
	 * Check the session data for an indication of whether this user has already sent this thanks.
	 *
	 * @param User $user The user being thanked.
	 * @param string $type Either 'rev' or 'log'.
	 * @param int $id The revision or log ID.
	 * @return bool
	 */
	private function userAlreadySentThanks( User $user, string $type, int $id ): bool {
		if ( $type === 'rev' ) {
			// For b/c with old-style keys
			$type = '';
		}
		return (bool)$user->getRequest()->getSessionData( "thanks-thanked-$type$id" );
	}

	private function getRevisionFromId( int $revId ): RevisionRecord {
		$revision = $this->revisionStore->getRevisionById( $revId );
		// Revision ID 1 means an invalid argument was passed in.
		if ( !$revision || $revision->getId() === 1 ) {
			$this->dieWithError( 'thanks-error-invalidrevision', 'invalidrevision' );
		}

		if ( $revision->isDeleted( RevisionRecord::DELETED_TEXT ) ) {
			$this->dieWithError( 'thanks-error-revdeleted', 'revdeleted' );
		}

		return $revision;
	}

	private function getLogEntryFromId( int $logId ): DatabaseLogEntry {
		$logEntry = DatabaseLogEntry::newFromId( $logId, $this->loadBalancer->getConnection( DB_REPLICA ) );

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

	private function getTitleFromRevision( RevisionRecord $revision ): Title {
		$title = Title::castFromPageIdentity( $revision->getPage() );
		if ( !$title ) {
			$this->dieWithError( 'thanks-error-notitle', 'notitle' );
		}
		return $title;
	}

	private function getUserFromRevision( RevisionRecord $revision ): User {
		$recipient = $revision->getUser();
		if ( !$recipient ) {
			$this->dieWithError( 'thanks-error-invalidrecipient', 'invalidrecipient' );
		}
		return $this->userFactory->newFromUserIdentity( $recipient );
	}

	/**
	 * Create the thanks notification event, and log the thanks.
	 *
	 * @param User $agent The thanks-sending user.
	 * @param string $type The thanks type ('rev' or 'log').
	 * @param int $id The log or revision ID.
	 * @param User $recipient The recipient of the thanks.
	 * @param Title $title The title of the page for which thanks is given.
	 * @param bool $revcreation True if the linked revision is a page creation.
	 *
	 * @return void
	 */
	private function sendThanks(
		User $agent, string $type, int $id, User $recipient, Title $title, bool $revcreation
	): void {
		$uniqueId = $type . '-' . $id;
		// Do one last check to make sure we haven't sent Thanks before
		if ( $this->haveAlreadyThanked( $agent, $uniqueId ) ) {
			// Pretend the thanks were sent
			$this->markResultSuccess( $recipient->getName() );
			return;
		}

		$agentUserTitle = Title::makeTitle( NS_USER_PROFILE, $agent->getName() );
		$recipientUserTitle = Title::makeTitle( NS_USER_PROFILE, $recipient->getName() );
		$broadcast = $this->notificationBroadcastFactory->newSingle(
			'user-interest-thanks-' . ( $revcreation ? 'creation' : 'edit' ),
			$agent,
			$recipient,
			[
				'url' => $title->getFullUrl(),
				'message' => [
					[ 'user_note', '' ],
					[ 1, $agentUserTitle->getFullUrl() ],
					[ 2, $agent->getName() ],
					[ 3, $recipientUserTitle->getFullURL() ],
					[ 4, $recipient->getName() ],
					[ 5, $title->getFullURL() ],
					[ 6, $title->getFullText() ]
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

	/** @inheritDoc */
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
		if ( $user->isAnon() ) {
			$this->dieWithError( 'thanks-error-notloggedin', 'notloggedin' );
		}

		if ( $user->pingLimiter( 'thanks-notification' ) ) {
			$this->dieWithError( [ 'thanks-error-ratelimited', $user->getName() ], 'ratelimited' );
		}

		$userBlock = $user->getBlock();
		if ( $userBlock && $userBlock->isSitewide() ) {
			$this->dieBlocked( $userBlock );
		}

		if ( $user->isBlockedGlobally() ) {
			$this->dieBlocked( $user->getGlobalBlock() );
		}
	}

	private function dieOnBadRecipient( User $user, User $recipient ) {
		if ( $user->getId() === $recipient->getId() ) {
			$this->dieWithError( 'thanks-error-invalidrecipient-self', 'invalidrecipient' );
		}

		if ( !$this->getConfig()->get( 'ThanksSendToBots' ) && $recipient->isBot() ) {
			$this->dieWithError( 'thanks-error-invalidrecipient-bot', 'invalidrecipient' );
		}
	}

	private function markResultSuccess( string $recipientName ): void {
		$this->getResult()->addValue( null, 'result', [
			'success' => 1,
			'recipient' => $recipientName,
		] );
	}

	private function haveAlreadyThanked( User $thanker, string $uniqueId ): bool {
		return (bool)$this->loadBalancer->getConnection( DB_PRIMARY )->selectRow(
			[ 'log_search', 'logging', 'actor' ],
			[ 'ls_value' ],
			[
				'actor_user' => $thanker->getId(),
				'ls_field' => 'thankid',
				'ls_value' => $uniqueId,
			],
			__METHOD__,
			[],
			[
				'logging' => [ 'INNER JOIN', 'ls_log_id=log_id' ],
				'actor' => [ 'JOIN', 'actor_id=log_actor']
			]
		);
	}

	/**
	 * @param User $user The user performing the thanks (and the log entry).
	 * @param User $recipient The target of the thanks (and the log entry).
	 * @param string $uniqueId A unique Id to identify the event being thanked for, to use
	 *                          when checking for duplicate thanks
	 */
	private function logThanks( User $user, User $recipient, string $uniqueId ): void {
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

	/** @inheritDoc */
	public function needsToken() {
		return 'csrf';
	}

	/** @inheritDoc */
	public function isWriteMode() {
		// Writes to the Echo database and sometimes log tables.
		return true;
	}

	/** @inheritDoc */
	public function getHelpUrls() {
		return [
			'https://www.mediawiki.org/wiki/Extension:Thanks#API_Documentation',
		];
	}

	/** @inheritDoc */
	protected function getExamplesMessages() {
		return [
			'action=thank&revid=456&source=diff&token=123ABC'
				=> 'apihelp-thank-example-1',
		];
	}
}
