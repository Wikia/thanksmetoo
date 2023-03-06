<?php

namespace ThanksMeToo;

use ApiMain;
use ApiUsageException;
use DerivativeRequest;
use FormSpecialPage;
use HTMLForm;
use Linker;
use MediaWiki\Session\CsrfTokenSet;
use MediaWiki\User\UserFactory;
use Status;

class SpecialThanks extends FormSpecialPage {
	/**
	 * API result
	 *
	 * @var array
	 */
	protected $result;

	/** 'rev' for revision, 'log' for log entry, null if no ID is specified */
	protected ?string $type;

	/**
	 * Revision or Log ID ('0' = invalid)
	 *
	 * @var string
	 */
	protected $id;

	public function __construct( private UserFactory $userFactory ) {
		parent::__construct( 'Thanks' );
	}

	/** @inheritDoc */
	public function doesWrites() {
		return true;
	}

	/**
	 * Set the type and ID or UUID of the request.
	 * @inheritDoc
	 */
	protected function setParameter( $par ) {
		if ( $par === null || $par === '' ) {
			$this->type = null;
			return;
		}

		$tokens = explode( '/', $par );
		if ( strtolower( $tokens[0] ) === 'log' ) {
			$this->type = 'log';
			// Make sure there's a numeric ID specified as the subpage.
			$this->id = count( $tokens ) === 1 || $tokens[1] === '' || !ctype_digit( $tokens[1] ) ?
				'0' :
				$tokens[1];
			return;
		}

		$this->type = 'rev';
		$this->id = !ctype_digit( $par ) ? '0' : $par;
	}

	/** @inheritDoc */
	protected function getFormFields() {
		return [
			'id' => [
				'id' => 'mw-thanks-form-id',
				'name' => 'id',
				'type' => 'hidden',
				'default' => $this->id,
			],
			'type' => [
				'id' => 'mw-thanks-form-type',
				'name' => 'type',
				'type' => 'hidden',
				'default' => $this->type,
			],
		];
	}

	/** @inheritDoc */
	protected function preHtml() {
		if ( $this->type === null ) {
			$msgKey = 'thanks-error-no-id-specified';
		} elseif ( $this->type === 'rev' && $this->id === '0' ) {
			$msgKey = 'thanks-error-invalidrevision';
		} elseif ( $this->type === 'log' && $this->id === '0' ) {
			$msgKey = 'thanks-error-invalid-log-id';
		} else {
			$msgKey = 'thanks-confirmation-special-' . $this->type;
		}
		return '<p>' . $this->msg( $msgKey )->escaped() . '</p>';
	}

	/** @inheritDoc */
	protected function alterForm( HTMLForm $form ) {
		if ( $this->type === null ||
			( in_array( $this->type, [ 'rev', 'log' ] ) && $this->id === '0' )
		) {
			$form->suppressDefaultSubmit();
		} else {
			$form->setSubmitText( $this->msg( 'thanks-submit' )->escaped() );
		}
	}

	/** @inheritDoc */
	protected function getDisplayFormat() {
		return 'ooui';
	}

	/** @inheritDoc */
	public function onSubmit( array $data ) {
		if ( !isset( $data['id'] ) ) {
			return Status::newFatal( 'thanks-error-invalidrevision' );
		}

		$requestData = [
			'action' => 'thank',
			$this->type => (int)$data['id'],
			'source' => 'specialpage',
			'token' => ( new CsrfTokenSet( $this->getRequest() ) )->getToken(),
		];

		$request = new DerivativeRequest( $this->getRequest(), $requestData, true );

		$api = new ApiMain( $request, true );

		try {
			$api->execute();
		} catch ( ApiUsageException $e ) {
			return Status::wrap( $e->getStatusValue() );
		}

		$this->result = $api->getResult()->getResultData( [ 'result' ] );
		return Status::newGood();
	}

	/** @inheritDoc */
	public function onSuccess() {
		$sender = $this->getUser();
		$recipient = $this->userFactory->newFromName( $this->result['recipient'] );
		$link = Linker::userLink( $recipient->getId(), $recipient->getName() );

		$msg = $this->msg( 'thanks-thanked-notice' )
			->rawParams( $link )
			->params( $recipient->getName(), $sender->getName() );
		$this->getOutput()->addHTML( $msg->parse() );
	}

	/** @inheritDoc */
	public function isListed() {
		return false;
	}
}
