<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class JwtMiddleware
{
    public function handle($request, Closure $next)
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json(['error' => 'Authorization token not provided'], 401);
        }

        try {
            $credentials = JWT::decode($token, new Key(config('jwt.secret'), config('jwt.algo')));
        } catch (ExpiredException $e) {
            return response()->json(['error' => 'Token has expired'], 401);
        } catch (\DomainException | \UnexpectedValueException $e) {
            return response()->json(['error' => 'Invalid token'], 401);
        }

        if (isset($credentials->jti) && Cache::has('jwt_blacklist:' . $credentials->jti)) {
            return response()->json(['error' => 'Token has been revoked'], 401);
        }

        $user = User::find($credentials->sub);

        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
