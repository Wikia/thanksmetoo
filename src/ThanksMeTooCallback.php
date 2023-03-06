<?php

namespace ThanksMeToo;

class ThanksMeTooCallback {
	public static function onRegistration() {
		global $wgReverbNotifications;

		$reverbNotifications = [
			'user-interest-thanks' => [ 'importance' => 0 ],
			'user-interest-thanks-creation' => [ 'importance' => 0 ],
			'user-interest-thanks-edit' => [ 'importance' => 0 ],
			'user-interest-thanks-log' => [ 'importance' => 0 ],
		];

		$wgReverbNotifications = array_merge( (array)$wgReverbNotifications, $reverbNotifications );
	}
}
