<?php

namespace JarredCain\CanvasLms\Auth\OAuth2;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use JarredCain\CanvasLms\Exceptions\AuthException;

class OAuthController
{
    public function __construct(private readonly OAuth2Handler $handler)
    {
    }

    /**
     * Redirect the user to Canvas for authorization.
     */
    public function redirect(): RedirectResponse
    {
        $url = $this->handler->buildAuthorizationUrl();
        return redirect()->away($url);
    }

    /**
     * Handle the OAuth2 callback from Canvas.
     *
     * The host application should override this controller or listen to the
     * canvas.oauth.success / canvas.oauth.failed events to handle post-auth logic.
     */
    public function callback(Request $request): mixed
    {
        if ($request->has('error')) {
            event('canvas.oauth.failed', [$request->input('error'), $request->input('error_description')]);

            throw new AuthException(
                "Canvas OAuth2 authorization denied: {$request->input('error_description', $request->input('error'))}"
            );
        }

        // Default storage key uses authenticated Laravel user ID if available
        $storageKey = auth()->check()
            ? 'user:' . auth()->id()
            : 'default';

        $tokenData = $this->handler->handleCallback($request, $storageKey);

        event('canvas.oauth.success', [$storageKey, $tokenData]);

        // Host app should handle the redirect — return a simple response as fallback
        if (config('canvas.oauth2.redirect_after_callback')) {
            return redirect()->to(config('canvas.oauth2.redirect_after_callback'));
        }

        return response()->json(['status' => 'authorized']);
    }
}
