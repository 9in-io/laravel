<?php

namespace Nine\Laravel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nine\Exceptions\NineException;
use Nine\Laravel\NineManager;

class AuthController extends Controller
{
    /** GET /nine/login?return_to=/path[&screen=signup][&login_hint=…] */
    public function login(Request $request, NineManager $nine)
    {
        $options = array_filter([
            'screen'     => $request->query('screen') === 'signup' ? 'signup' : null,
            'login_hint' => is_string($request->query('login_hint')) ? $request->query('login_hint') : null,
        ]);

        return $nine->redirectToLogin($request->session(), $request->query('return_to'), $options);
    }

    /** GET /nine/callback */
    public function callback(Request $request, NineManager $nine)
    {
        try {
            $tokens = $nine->handleCallback($request);
        } catch (NineException $e) {
            abort($e->getStatus() && $e->getStatus() < 500 ? $e->getStatus() : 400, $e->getMessage());
        }

        return redirect()->to($nine->safeReturnTo($tokens->returnTo));
    }

    /** GET|POST /nine/logout[?return_to=/path] */
    public function logout(Request $request, NineManager $nine)
    {
        $returnTo = $request->input('return_to');

        return $nine->logout($request->session(), is_string($returnTo) ? $nine->safeReturnTo($returnTo) : null);
    }
}
