<?php

namespace ThanksMeToo;

use Config;
use GenderCache;
use Html;
use MediaWiki\Auth\Hook\LocalUserCreatedHook;
use MediaWiki\Diff\Hook\DifferenceEngineViewHeaderHook;
use MediaWiki\Diff\Hook\DiffToolsHook;
use MediaWiki\Hook\BeforePageDisplayHook;
use MediaWiki\Hook\GetLogTypesOnUserHook;
use MediaWiki\Hook\HistoryToolsHook;
use MediaWiki\Hook\LogEventsListLineEndingHook;
use MediaWiki\Hook\PageHistoryBeforeListHook;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserOptionsManager;
use MobileContext;
use OutputPage;
use RequestContext;
use SpecialPage;
use User;

class ThanksHooks implements
	HistoryToolsHook,
	DiffToolsHook,
	LogEventsListLineEndingHook,
	BeforePageDisplayHook,
	GetLogTypesOnUserHook,
	LocalUserCreatedHook,
	DifferenceEngineViewHeaderHook,
	PageHistoryBeforeListHook
{

	public function __construct(
		private UserFactory $userFactory,
		private UserOptionsManager $userOptionsManager,
		private Config $config,
		private GenderCache $genderCache
	) {
	}

	public function onDiffTools( $newRevRecord, &$links, $oldRevRecord, $userIdentity ) {
		$this->historyToolsAndDiffRevisionToolsHooksHandler( $newRevRecord, $links, $oldRevRecord, $userIdentity );
	}

	public function onHistoryTools( $revRecord, &$links, $prevRevRecord, $userIdentity ) {
		$this->historyToolsAndDiffRevisionToolsHooksHandler( $revRecord, $links, $prevRevRecord, $userIdentity );
	}

	/**
	 * Handler for HistoryRevisionTools and DiffRevisionTools hooks.
	 * Inserts 'thank' link into revision interface
	 *
	 * @param RevisionRecord $revRecord Revision object to add the thank link for
	 * @param string[] &$links Links to add to the revision interface
	 * @param RevisionRecord|null $prevRevRecord Revision object of the "old" revision when viewing a diff
	 * @param UserIdentity $actingUser The user performing the thanks.
	 */
	private function historyToolsAndDiffRevisionToolsHooksHandler(
		RevisionRecord $revRecord,
		array &$links,
		?RevisionRecord $prevRevRecord,
		UserIdentity $actingUser
	): void {
		$revAuthor = $revRecord->getUser();
		// Disallow thanks if this revision or its authorship info was deleted (CATS-3055)
		if ( $revAuthor === null ) {
			return;
		}

		$recipientId = $revAuthor->getId();

		$recipient = $this->userFactory->newFromUserIdentity( $revAuthor );
		$performer = $this->userFactory->newFromUserIdentity( $actingUser );
		$prevRevisionId = $revRecord->getParentId();
		// Don't let users thank themselves.
		// Exclude anonymous users.
		// Exclude users who are blocked.
		// Check whether bots are allowed to receive thanks.
		// Check if there's other revisions between $prev and $oldRev
		// (It supports discontinuous history created by Import or CX but
		// prevents thanking diff across multiple revisions)
		if ( !$performer->isAnon()
			&& $recipientId !== $performer->getId()
			&& $performer->getBlock() === null
			&& !$performer->isBlockedGlobally()
			&& $this->canReceiveThanks( $recipient )
			&& !$revRecord->isDeleted( RevisionRecord::DELETED_TEXT )
			&& ( !$prevRevRecord || !$prevRevisionId || $prevRevisionId === $prevRevRecord->getId() )
		) {
			$links[] = $this->generateThankElement( $performer, $revRecord->getId(), $recipient );
		}
	}

	/** Check whether a user is allowed to receive thanks or not */
	private function canReceiveThanks( User $user ): bool {
		if ( $user->isAnon() ) {
			return false;
		}

		if ( !$this->config->get( 'ThanksSendToBots' ) && $user->isBot() ) {
			return false;
		}

		return true;
	}

	/** Creates either a thank link or thanked span based on users session */
	private function generateThankElement(
		User $performer, ?int $id, User $recipient, string $type = 'revision'
	): string {
		// Check if the user has already thanked for this revision or log entry.
		// Session keys are backwards-compatible, and are also used in the ApiCoreThank class.
		$sessionKey = ( $type === 'revision' ) ? $id : $type . $id;
		if ( $performer->getRequest()->getSessionData( "thanks-thanked-$sessionKey" ) ) {
			return Html::element(
				'span',
				[ 'class' => 'mw-thanks-thanked' ],
				wfMessage( 'thanks-thanked', $performer, $recipient->getName() )->text()
			);
		}

		$subpage = ( $type === 'revision' ) ? '' : 'Log/';
		return Html::element(
			'a',
			[
				'class' => 'mw-thanks-thank-link',
				'href' => SpecialPage::getTitleFor( 'Thanks', $subpage . $id )->getFullURL(),
				'title' => wfMessage( 'thanks-thank-tooltip' )
					->params( $performer->getName(), $recipient->getName() )
					->text(),
				'data-' . $type . '-id' => $id,
				'data-recipient-gender' => $this->genderCache->getGenderOf( $recipient->getName(), __METHOD__ ),
			],
			wfMessage( 'thanks-thank', $performer, $recipient->getName() )->text()
		);
	}

	private function addThanksModule( OutputPage $outputPage ): void {
		$outputPage->addModules( [ 'ext.thanks.corethank' ] );
		$outputPage->addJsConfigVars(
			'thanks-confirmation-required',
			$this->config->get( 'ThanksConfirmationRequired' )
		);
	}

	/** @inheritDoc */
	public function onPageHistoryBeforeList( $article, $context ) {
		if ( $context->getUser()->isRegistered() ) {
			$this->addThanksModule( $context->getOutput() );
		}
	}

	/** @inheritDoc */
	public function onDifferenceEngineViewHeader( $differenceEngine ) {
		if ( $differenceEngine->getUser()->isRegistered() ) {
			$this->addThanksModule( $differenceEngine->getOutput() );
		}
	}

	/** @inheritDoc */
	public function onLocalUserCreated( $user, $autocreated ) {
		// New users get echo preferences set that are not the default settings for existing users.
		// Specifically, new users are opted into email notifications for thanks.
		if ( !$autocreated ) {
			// TODO: we always return true so why this check??
			$this->userOptionsManager->setOption( $user, 'echo-subscriptions-email-edit-thank', true );
			// HYD-4639
			// $user->saveSettings();
		}
	}

	/**
	 * Add thanks button to SpecialMobileDiff page
	 *
	 * @param OutputPage &$output OutputPage object
	 * @param MobileContext $ctx MobileContext object
	 * @param RevisionRecord[]|null[] $revisions Array of the two revisions that are being compared in the diff
	 */
	public function onBeforeSpecialMobileDiffDisplay( &$output, $ctx, $revisions ): void {
		$currentRevision = $revisions[1];

		// If the MobileFrontend extension is installed and the user is
		// logged in or recipient is not a bot if bots cannot receive thanks, show a 'Thank' link.
		if ( $currentRevision ) {
			$user = $this->userFactory->newFromId( $currentRevision->getUser()->getId() );
			if ( $this->canReceiveThanks( $user ) && $output->getUser()->isRegistered() ) {
				$output->addModules( [ 'ext.thanks.mobilediff' ] );

				if ( $output->getRequest()->getSessionData( 'thanks-thanked-' . $currentRevision->getId() ) ) {
					// User already sent thanks for this revision
					$output->addJsConfigVars( 'wgThanksAlreadySent', true );
				}

			}
		}
	}

	/** @inheritDoc So users can just type in a username for target and it'll work. */
	public function onGetLogTypesOnUser( &$types ) {
		$types[] = 'thanks';
	}

	/** @inheritDoc */
	public function onBeforePageDisplay( $out, $skin ): void {
		if ( $out->getTitle()->isSpecial( 'Log' ) ) {
			$this->addThanksModule( $out );
		}
	}

	/** @inheritDoc */
	public function onLogEventsListLineEnding( $page, &$ret, $entry, &$classes, &$attribs ) {
		$performer = RequestContext::getMain()->getUser();

		// Don't thank if anonymous or blocked
		if ( !$performer || $performer->isAnon() || $performer->getBlock() || $performer->isBlockedGlobally() ) {
			return;
		}

		// Make sure this log type is whitelisted.
		if ( !in_array( $entry->getType(), $this->config->get( 'ThanksLogTypeWhitelist' ) ) ) {
			return;
		}

		// Don't thank if no recipient,
		// or if recipient is the current user or unable to receive thanks.
		// Don't check for deleted revision (this avoids extraneous queries from Special:Log).
		$recipient = $this->userFactory->newFromUserIdentity( $entry->getPerformerIdentity() );
		if ( $recipient->getId() === $performer->getId() || !$this->canReceiveThanks( $recipient ) ) {
			return;
		}

		// Create thank link either for the revision (if there is an associated revision ID)
		// or the log entry.
		$type = $entry->getAssociatedRevId() ? 'revision' : 'log';
		$id = $entry->getAssociatedRevId() ?: $entry->getId();
		$thankLink = $this->generateThankElement( $performer, $id, $recipient, $type );

		// Add parentheses to match what's done with Thanks in revision lists and diff displays.
		$ret .= ' ' . wfMessage( 'parentheses' )->rawParams( $thankLink )->escaped();
	}
}
