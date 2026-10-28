<?php

namespace App\Auth\Sso;

use RuntimeException;

/**
 * ClaimsVerificationError of openidconnect: why an ID token was refused, as it displays.
 */
final class ClaimsVerificationError extends RuntimeException {}
