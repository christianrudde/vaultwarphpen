<?php

namespace Tests\Unit\Notifications;

use App\Notifications\Subscriptions;
use PHPUnit\Framework\TestCase;

class SubscriptionsTest extends TestCase
{
    public function test_anonymous_connections_are_limited_per_address_and_in_all(): void
    {
        $subscriptions = new Subscriptions;
        for ($i = 0; $i < Subscriptions::MAX_ANONYMOUS_CONNECTIONS_PER_IP; $i++) {
            $this->assertTrue($subscriptions->tryReserve('2001:db8::1'));
        }
        $this->assertFalse($subscriptions->tryReserve('2001:db8::1'));

        for ($i = Subscriptions::MAX_ANONYMOUS_CONNECTIONS_PER_IP; $i < Subscriptions::MAX_ANONYMOUS_CONNECTIONS; $i++) {
            $this->assertTrue($subscriptions->tryReserve('2001:db8::'.dechex($i)));
        }
        $this->assertFalse($subscriptions->tryReserve('2001:db8::ffff'));

        // A released slot can be taken again, from any address.
        $subscriptions->release('2001:db8::1');
        $this->assertTrue($subscriptions->tryReserve('2001:db8::ffff'));
    }
}
