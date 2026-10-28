<?php

namespace App\Support;

use RuntimeException;

/**
 * A request of HttpClient::get that failed: reqwest's errors, including an
 * error status (error_for_status).
 */
class HttpRequestFailed extends RuntimeException {}
