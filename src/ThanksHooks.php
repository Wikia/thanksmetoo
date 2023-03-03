<?php

namespace ThanksMeToo;

use Article;
use CategoryPage;
use ConfigException;
use DatabaseLogEntry;
use DifferenceEngine;
use Html;
use ImagePage;
use LogEventsList;
use MediaWiki\Diff\Hook\DiffToolsHook;
use MediaWiki\Hook\HistoryToolsHook;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use MobileContext;
use OutputPage;
use Page;
use RequestContext;
use ResourceLoader;
use Skin;
use SpecialPage;
use User;
use WikiPage;

/**
 * Hooks for Thanks extension
 *
 * @file
 * @ingroup Extensions
 */
class ThanksHooks implements HistoryToolsHook, DiffToolsHook {

	/** @var UserFactory */
	private $userFactory;

	public function __construct( UserFactory $userFactory ) {
		$this->userFactory = $userFactory;
	}

	/**
	 * ResourceLoaderTestModules hook handler
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/ResourceLoaderTestModules
	 *
	 * @param array &$testModules The modules array to add to.
	 * @param ResourceLoader &$resourceLoader The resource loader.
	 * @return bool
	 */
	public static function onResourceLoaderTestModules( array &$testModules,
		ResourceLoader &$resourceLoader
	) {
		if ( class_exists( 'SpecialMobileDiff' ) ) {
			$testModules['qunit']['tests.ext.thanks.mobilediff'] = [
				'localBasePath' => dirname( __DIR__ ),
				'remoteExtPath' => 'Thanks',
				'dependencies' => [ 'ext.thanks.mobilediff' ],
				'scripts' => [
					'tests/qunit/test_ext.thanks.mobilediff.js',
				],
				'targets' => [ 'desktop', 'mobile' ],
			];
		}
		return true;
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
	public function historyToolsAndDiffRevisionToolsHooksHandler(
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
			&& self::canReceiveThanks( $recipient )
			&& !$revRecord->isDeleted( RevisionRecord::DELETED_TEXT )
			&& ( !$prevRevRecord || !$prevRevisionId || $prevRevisionId === $prevRevRecord->getId() )
		) {
			$links[] = self::generateThankElement( $revRecord->getId(), $recipient );
		}
	}

	/**
	 * Check whether a user is allowed to receive thanks or not
	 *
	 * @param User $user Recipient
	 * @return bool true if allowed, false if not
	 */
	protected static function canReceiveThanks( User $user ) {
		global $wgThanksSendToBots;

		if ( $user->isAnon() ) {
			return false;
		}

		if ( !$wgThanksSendToBots && $user->isBot() ) {
			return false;
		}

		return true;
	}

	/**
	 * Helper for self::historyToolsAndDiffRevisionToolsHooksHandler
	 * Creates either a thank link or thanked span based on users session
	 *
	 * @param int $id Revision or log ID to generate the thank element for.
	 * @param User $recipient User who receives thanks notification.
	 * @param string $type Either 'revision' or 'log'.
	 * @return string
	 */
	protected static function generateThankElement( $id, $recipient, $type = 'revision' ) {
		global $wgUser;
		// Check if the user has already thanked for this revision or log entry.
		// Session keys are backwards-compatible, and are also used in the ApiCoreThank class.
		$sessionKey = ( $type === 'revision' ) ? $id : $type . $id;
		if ( $wgUser->getRequest()->getSessionData( "thanks-thanked-$sessionKey" ) ) {
			return Html::element(
				'span',
				[ 'class' => 'mw-thanks-thanked' ],
				wfMessage( 'thanks-thanked', $wgUser, $recipient->getName() )->text()
			);
		}

		$genderCache = MediaWikiServices::getInstance()->getGenderCache();
		// Add 'thank' link
		$tooltip = wfMessage( 'thanks-thank-tooltip' )
			->params( $wgUser->getName(), $recipient->getName() )
			->text();

		$subpage = ( $type === 'revision' ) ? '' : 'Log/';
		return Html::element(
			'a',
			[
				'class' => 'mw-thanks-thank-link',
				'href' => SpecialPage::getTitleFor( 'Thanks', $subpage . $id )->getFullURL(),
				'title' => $tooltip,
				'data-' . $type . '-id' => $id,
				'data-recipient-gender' => $genderCache->getGenderOf( $recipient->getName(), __METHOD__ ),
			],
			wfMessage( 'thanks-thank', $wgUser, $recipient->getName() )->text()
		);
	}

	/**
	 * @param OutputPage $outputPage The OutputPage to add the module to.
	 */
	protected static function addThanksModule( OutputPage $outputPage ) {
		$confirmationRequired = MediaWikiServices::getInstance()
			->getMainConfig()
			->get( 'ThanksConfirmationRequired' );
		$outputPage->addModules( [ 'ext.thanks.corethank' ] );
		$outputPage->addJsConfigVars( 'thanks-confirmation-required', $confirmationRequired );
	}

	/**
	 * Handler for PageHistoryBeforeList hook.
	 *
	 * @see    http://www.mediawiki.org/wiki/Manual:Hooks/PageHistoryBeforeList
	 * @param WikiPage|Article|ImagePage|CategoryPage|Page &$page The page for which the history
	 *                                                               is loading.
	 * @param RequestContext $context RequestContext object
	 * @return bool true in all cases
	 */
	public static function onPageHistoryBeforeList( &$page, $context ) {
		if ( $context->getUser()->isRegistered() ) {
			static::addThanksModule( $context->getOutput() );
		}
		return true;
	}

	/**
	 * Handler for DiffViewHeader hook.
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/DifferenceEngineViewHeader
	 * @param DifferenceEngine $diff DifferenceEngine object that's calling.
	 * @return bool true in all cases
	 */
	public static function onDifferenceEngineViewHeader( $diff ) {
		if ( $diff->getUser()->isRegistered() ) {
			static::addThanksModule( $diff->getOutput() );
		}
		return true;
	}

	/**
	 * Handler for LocalUserCreated hook
	 *
	 * @see    http://www.mediawiki.org/wiki/Manual:Hooks/LocalUserCreated
	 * @param User $user User object that was created.
	 * @param bool $autocreated True when account was auto-created
	 * @return bool
	 */
	public static function onAccountCreated( $user, $autocreated ) {
		// New users get echo preferences set that are not the default settings for existing users.
		// Specifically, new users are opted into email notifications for thanks.
		if ( !$autocreated ) {
			// TODO  martyna: we always return true so why this check??
			MediaWikiServices::getInstance()
				->getUserOptionsManager()
				->setOption( $user, 'echo-subscriptions-email-edit-thank', true );
			// HYD-4639
			// $user->saveSettings();
		}
		return true;
	}

	/**
	 * Add thanks button to SpecialMobileDiff page
	 *
	 * @param OutputPage &$output OutputPage object
	 * @param MobileContext $ctx MobileContext object
	 * @param array $revisions Array of the two revisions that are being compared in the diff
	 * @return bool true in all cases
	 */
	public static function onBeforeSpecialMobileDiffDisplay( &$output, $ctx, $revisions ) {
		$rev = $revisions[1];

		// If the MobileFrontend extension is installed and the user is
		// logged in or recipient is not a bot if bots cannot receive thanks, show a 'Thank' link.
		if ( $rev ) {
			$user = MediaWikiServices::getInstance()
				->getUserFactory()
				->newFromId( $rev->getUser()->getId() );
			if ( class_exists( 'SpecialMobileDiff' )
				&& self::canReceiveThanks( $user )
				&& $output->getUser()->isRegistered()
			) {
				$output->addModules( [ 'ext.thanks.mobilediff' ] );

				if ( $output->getRequest()->getSessionData( 'thanks-thanked-' . $rev->getId() ) ) {
					// User already sent thanks for this revision
					$output->addJsConfigVars( 'wgThanksAlreadySent', true );
				}

			}
		}
		return true;
	}

	/**
	 * Handler for GetLogTypesOnUser.
	 * So users can just type in a username for target and it'll work.
	 *
	 * @link   https://www.mediawiki.org/wiki/Manual:Hooks/GetLogTypesOnUser
	 * @param string[] &$types The list of log types, to add to.
	 * @return bool
	 */
	public static function onGetLogTypesOnUser( array &$types ) {
		$types[] = 'thanks';
		return true;
	}

	/**
	 * Handler for BeforePageDisplay.  Inserts javascript to enhance thank
	 * links from static urls to in-page dialogs along with reloading
	 * the previously thanked state.
	 *
	 * @link   https://www.mediawiki.org/wiki/Manual:Hooks/BeforePageDisplay
	 * @param OutputPage $out OutputPage object
	 * @param Skin $skin The skin in use.
	 * @return bool
	 */
	public static function onBeforePageDisplay( OutputPage $out, $skin ) {
		$title = $out->getTitle();
		// Add to Special:Log.
		if ( $title->isSpecial( 'Log' ) ) {
			static::addThanksModule( $out );
		}
		return true;
	}

	/**
	 * @link   https://www.mediawiki.org/wiki/Manual:Hooks/LogEventsListLineEnding
	 * @param LogEventsList $page The log events list.
	 * @param string &$ret The lineending HTML, to modify.
	 * @param DatabaseLogEntry $entry The log entry.
	 * @param string[] &$classes CSS classes to add to the line.
	 * @param string[] &$attribs HTML attributes to add to the line.
	 * @throws ConfigException
	 */
	public static function onLogEventsListLineEnding(
		LogEventsList $page, &$ret, DatabaseLogEntry $entry, &$classes, &$attribs
	) {
		global $wgUser;

		// Don't thank if anonymous or blocked
		if ( $wgUser->isAnon() || $wgUser->isBlocked() || $wgUser->isBlockedGlobally() ) {
			return;
		}

		// Make sure this log type is whitelisted.
		$logTypeWhitelist = MediaWikiServices::getInstance()
			->getMainConfig()
			->get( 'ThanksLogTypeWhitelist' );
		if ( !in_array( $entry->getType(), $logTypeWhitelist ) ) {
			return;
		}

		// Don't thank if no recipient,
		// or if recipient is the current user or unable to receive thanks.
		// Don't check for deleted revision (this avoids extraneous queries from Special:Log).
		$recipient = MediaWikiServices::getInstance()
			->getUserFactory()
			->newFromId( $entry->getPerformerIdentity()->getId() );
		if ( !$recipient
			|| $recipient->getId() === $wgUser->getId()
			|| !self::canReceiveThanks( $recipient )
		) {
			return;
		}

		// Create thank link either for the revision (if there is an associated revision ID)
		// or the log entry.
		$type = $entry->getAssociatedRevId() ? 'revision' : 'log';
		$id = $entry->getAssociatedRevId() ? $entry->getAssociatedRevId() : $entry->getId();
		$thankLink = self::generateThankElement( $id, $recipient, $type );

		// Add parentheses to match what's done with Thanks in revision lists and diff displays.
		$ret .= ' ' . wfMessage( 'parentheses' )->rawParams( $thankLink )->escaped();
	}

	/**
	 * Setup anything that needs to be configured before anything else runs.
	 *
	 * @return void
	 */
	public static function onRegistration() {
		global $wgReverbNotifications;

		$reverbNotifications = [
			'user-interest-thanks' => [
				'importance' => 0
			],
			'user-interest-thanks-creation' => [
				'importance' => 0
			],
			'user-interest-thanks-edit' => [
				'importance' => 0
			],
			'user-interest-thanks-log' => [
				'importance' => 0
			],
		];

		$wgReverbNotifications = array_merge( (array)$wgReverbNotifications, $reverbNotifications );
	}
}
