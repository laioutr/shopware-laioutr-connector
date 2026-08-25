<?php

declare(strict_types=1);

namespace Laioutr\Connector\Tests\Unit\Embedded\Business;

use Laioutr\Connector\Embedded\Business\CheckoutReturnDecision;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CheckoutReturnDecisionTest extends TestCase
{
    private const FINISH = 'frontend.checkout.finish.page';
    private const EDIT = 'frontend.account.edit-order.page';
    private const THANKS = 'http://localhost/thank-you';
    private const CHECKOUT = 'http://localhost/checkout';

    #[DataProvider('caseProvider')]
    public function testDecide(
        string $route,
        int $status,
        ?string $location,
        ?string $orderId,
        bool $embedded,
        ?string $expected,
    ): void {
        $decision = new CheckoutReturnDecision();

        static::assertSame($expected, $decision->decide(
            $route,
            $status,
            $location,
            $orderId,
            self::THANKS,
            self::CHECKOUT,
            $embedded,
        ));
    }

    /**
     * @return iterable<string, array{string, int, string|null, string|null, bool, string|null}>
     */
    public static function caseProvider(): iterable
    {
        yield 'paid, session intact' => [self::FINISH, 200, null, 'ord1', true, self::THANKS . '?order=ord1'];
        yield 'paid, session lost' => [self::FINISH, 302, '/checkout/register', 'ord1', true, self::THANKS . '?order=ord1'];
        yield 'paid, un-embedded' => [self::FINISH, 200, null, 'ord1', false, self::THANKS . '?order=ord1'];
        yield 'finish without an order id' => [self::FINISH, 200, null, null, true, null];
        yield 'payment failed via finish' => [
            self::FINISH, 302, '/account/order/edit/ord1', 'ord1', true,
            self::CHECKOUT . '?retry-order=ord1',
        ];
        yield 'cart error' => [self::FINISH, 302, '/checkout/cart', 'ord1', true, null];
        yield 'edit-order embedded' => [self::EDIT, 200, null, 'ord1', true, self::CHECKOUT . '?retry-order=ord1'];
        yield 'edit-order un-embedded is left alone' => [self::EDIT, 200, null, 'ord1', false, null];
        yield 'unrelated route' => ['frontend.account.home.page', 200, null, 'ord1', true, null];
    }

    public function testFinishFallsThroughWithoutATarget(): void
    {
        $decision = new CheckoutReturnDecision();

        static::assertNull($decision->decide(self::FINISH, 200, null, 'ord1', null, self::CHECKOUT, true));
    }

    public function testRetryFallsThroughWithoutATarget(): void
    {
        $decision = new CheckoutReturnDecision();

        static::assertNull($decision->decide(self::EDIT, 200, null, 'ord1', self::THANKS, null, true));
    }

    public function testErrorCodeIsForwarded(): void
    {
        $decision = new CheckoutReturnDecision();

        static::assertSame(
            self::CHECKOUT . '?retry-order=ord1&error-code=CHECKOUT__CUSTOMER_CANCELED_EXTERNAL_PAYMENT',
            $decision->decide(
                self::EDIT,
                200,
                null,
                'ord1',
                self::THANKS,
                self::CHECKOUT,
                true,
                'CHECKOUT__CUSTOMER_CANCELED_EXTERNAL_PAYMENT',
            ),
        );
    }
}
