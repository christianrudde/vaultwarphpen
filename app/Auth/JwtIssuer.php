<?php

namespace App\Auth;

/**
 * What a token was issued for; the issuer claim is `<domain origin>|<value>`.
 */
enum JwtIssuer: string
{
    case Login = 'login';
    case Invite = 'invite';
    case EmergencyAccessInvite = 'emergencyaccessinvite';
    case Delete = 'delete';
    case VerifyEmail = 'verifyemail';
    case Admin = 'admin';
    case Send = 'send';
    case OrgApiKey = 'api.organization';
    case FileDownload = 'file_download';
    case RegisterVerify = 'register_verify';
    case TwoFactorRemember = '2faremember';
    // The user verification tokens of the two-factor settings.
    case TwoFactorAuth = 'api.2fa';
}
