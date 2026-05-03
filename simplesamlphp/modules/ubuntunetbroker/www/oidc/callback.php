<?php

declare(strict_types=1);

use SimpleSAML\Auth;
use SimpleSAML\Error\BadRequest;
use SimpleSAML\Module\ubuntunetbroker\Auth\Source\OidcGeneric;

if (!isset($_GET['state']) || !is_string($_GET['state']) || trim($_GET['state']) === '') {
    throw new BadRequest('Missing OIDC state parameter.');
}

$state = Auth\State::loadState($_GET['state'], OidcGeneric::STAGE_ID);
$authSourceId = $state[OidcGeneric::AUTH_ID] ?? null;
$source = Auth\Source::getById(is_string($authSourceId) ? $authSourceId : '');

if (!$source instanceof OidcGeneric) {
    throw new \SimpleSAML\Error\Exception('OIDC callback received an invalid authentication source.');
}

$source->completeOidc($state, $_GET);
