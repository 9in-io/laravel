<?php

namespace Nine\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Nine\OAuth2Client client()
 * @method static \Nine\TokenVerifier verifier()
 * @method static \Nine\ManagementClient management()
 * @method static \Nine\HybridTokenVerifier tokenVerifier()
 * @method static int handleTokenEvent(array $event)
 * @method static array verifyAccessToken(?string $token, array $options = [])
 * @method static \Illuminate\Http\RedirectResponse redirectToLogin(\Illuminate\Contracts\Session\Session $session, ?string $returnTo = null, array $options = [])
 * @method static \Nine\TokenSet|null tokens(\Illuminate\Contracts\Session\Session $session)
 * @method static \Illuminate\Http\RedirectResponse logout(\Illuminate\Contracts\Session\Session $session, ?string $returnTo = null)
 *
 * @see \Nine\Laravel\NineManager
 */
class Nine extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'nine';
    }
}
