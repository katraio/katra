<?php

namespace App\Auth;

use Closure;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Throwable;

final class OrganizationScope
{
    /**
     * Run work inside one Organization's Bouncer scope and always restore the prior scope.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     *
     * @throws Throwable
     */
    public function run(int $organizationId, Closure $callback): mixed
    {
        $scope = Bouncer::scope();
        $previousScope = $scope->get();

        $scope->to($organizationId);

        try {
            return $callback();
        } finally {
            if ($previousScope === null) {
                $scope->remove();
            } else {
                $scope->to($previousScope);
            }
        }
    }

    public function clear(): void
    {
        Bouncer::scope()->remove();
    }
}
