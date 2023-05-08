<?php

namespace ThanksMeToo;

class ThanksMeTooCallback {
	public static function onRegistration() {
		global $wgReverbNotifications;

		$reverbNotifications = [
			'user-interest-thanks' => [ 'importance' => 0 ],
		];

		$wgReverbNotifications = array_merge( (array)$wgReverbNotifications, $reverbNotifications );
	}
}
