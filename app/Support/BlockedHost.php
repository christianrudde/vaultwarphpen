<?php

namespace App\Support;

/**
 * CustomHttpClientError (src/http_client.rs): a host that is invalid or that
 * HTTP_REQUEST_BLOCK_REGEX or HTTP_REQUEST_BLOCK_NON_GLOBAL_IPS blocks.
 * Callers tell it apart from other failures, as Vaultwarden does with
 * CustomHttpClientError::downcast_ref.
 */
final class BlockedHost extends HttpRequestFailed {}
