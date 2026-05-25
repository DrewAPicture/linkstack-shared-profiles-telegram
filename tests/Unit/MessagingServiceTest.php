<?php

declare(strict_types=1);

namespace WerdsWords\LinkStack\SharedProfiles\Providers\Telegram\Tests\Unit;

use Illuminate\Support\Facades\Http;
use Laravel\Socialite\SocialiteServiceProvider;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use WerdsWords\LinkStack\SharedProfiles\Providers\Telegram\ServiceProvider;
use WerdsWords\LinkStack\SharedProfiles\Providers\Telegram\Services\MessagingService;
use WerdsWords\LinkStack\SharedProfiles\Providers\Telegram\Tests\Support\Models\User;
use WerdsWords\LinkStack\SharedProfiles\ServiceProvider as CoreServiceProvider;

#[CoversClass(MessagingService::class)]
final class MessagingServiceTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            SocialiteServiceProvider::class,
            CoreServiceProvider::class,
            ServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('auth.providers.users.model', User::class);

        $app['config']->set('services.telegram.client_id', 'test-bot-id');
        $app['config']->set('services.telegram.client_secret', 'test-secret');
        $app['config']->set('services.telegram.redirect', 'https://example.com/callback');
    }

    // -------------------------------------------------------------------------
    // Container binding
    // -------------------------------------------------------------------------

    public function testServiceIsResolvableFromContainer(): void
    {
        $this->assertInstanceOf(MessagingService::class, app(MessagingService::class));
    }

    public function testServiceIsBoundAsSingleton(): void
    {
        $a = app(MessagingService::class);
        $b = app(MessagingService::class);

        $this->assertSame($a, $b);
    }

    // -------------------------------------------------------------------------
    // sendMessage()
    // -------------------------------------------------------------------------

    public function testSendMessagePostsToCorrectTelegramApiUrl(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $service = new MessagingService;
        $service->sendMessage('my-bot-token', '12345678', 'Hello!');

        Http::assertSent(
            fn ($request) => $request->url() === 'https://api.telegram.org/botmy-bot-token/sendMessage'
        );
    }

    public function testSendMessageIncludesChatIdAndText(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $service = new MessagingService;
        $service->sendMessage('my-bot-token', '12345678', 'Hello!');

        Http::assertSent(
            fn ($request) => $request['chat_id'] === '12345678' && $request['text'] === 'Hello!'
        );
    }

    public function testSendMessageReturnsTrueOnSuccess(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $service = new MessagingService;
        $result = $service->sendMessage('my-bot-token', '12345678', 'Hello!');

        $this->assertTrue($result);
    }

    public function testSendMessageReturnsFalseOnApiError(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Bad Request'], 400)]);

        $service = new MessagingService;
        $result = $service->sendMessage('my-bot-token', '12345678', 'Hello!');

        $this->assertFalse($result);
    }

    public function testSendMessageReturnsFalseOnServerError(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response([], 500)]);

        $service = new MessagingService;
        $result = $service->sendMessage('my-bot-token', '12345678', 'Hello!');

        $this->assertFalse($result);
    }

    // -------------------------------------------------------------------------
    // sendMessageWithKeyboard()
    // -------------------------------------------------------------------------

    public function testSendMessageWithKeyboardPostsToCorrectTelegramApiUrl(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $service = new MessagingService;
        $service->sendMessageWithKeyboard('my-bot-token', '12345678', 'Pick one:', []);

        Http::assertSent(
            fn ($request) => $request->url() === 'https://api.telegram.org/botmy-bot-token/sendMessage'
        );
    }

    public function testSendMessageWithKeyboardIncludesChatIdTextAndReplyMarkup(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $keyboard = [[['text' => '✅ Approve', 'callback_data' => 'approve:1']]];

        $service = new MessagingService;
        $service->sendMessageWithKeyboard('my-bot-token', '12345678', 'Pick one:', $keyboard);

        Http::assertSent(function ($request) use ($keyboard) {
            return $request['chat_id'] === '12345678'
                && $request['text'] === 'Pick one:'
                && $request['reply_markup'] === ['inline_keyboard' => $keyboard];
        });
    }

    public function testSendMessageWithKeyboardReturnsTrueOnSuccess(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $service = new MessagingService;
        $result = $service->sendMessageWithKeyboard('my-bot-token', '12345678', 'Pick one:', []);

        $this->assertTrue($result);
    }

    public function testSendMessageWithKeyboardReturnsFalseOnApiError(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false], 400)]);

        $service = new MessagingService;
        $result = $service->sendMessageWithKeyboard('my-bot-token', '12345678', 'Pick one:', []);

        $this->assertFalse($result);
    }

    // -------------------------------------------------------------------------
    // editMessageText()
    // -------------------------------------------------------------------------

    public function testEditMessageTextPostsToCorrectTelegramApiUrl(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $service = new MessagingService;
        $service->editMessageText('my-bot-token', '12345678', 99, 'Updated text');

        Http::assertSent(
            fn ($request) => $request->url() === 'https://api.telegram.org/botmy-bot-token/editMessageText'
        );
    }

    public function testEditMessageTextIncludesChatIdMessageIdTextAndEmptyKeyboard(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $service = new MessagingService;
        $service->editMessageText('my-bot-token', '12345678', 99, 'Updated text');

        Http::assertSent(function ($request) {
            return $request['chat_id'] === '12345678'
                && $request['message_id'] === 99
                && $request['text'] === 'Updated text'
                && $request['reply_markup'] === ['inline_keyboard' => []];
        });
    }

    public function testEditMessageTextReturnsTrueOnSuccess(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $service = new MessagingService;
        $result = $service->editMessageText('my-bot-token', '12345678', 99, 'Updated text');

        $this->assertTrue($result);
    }

    public function testEditMessageTextReturnsFalseOnApiError(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false], 400)]);

        $service = new MessagingService;
        $result = $service->editMessageText('my-bot-token', '12345678', 99, 'Updated text');

        $this->assertFalse($result);
    }

    // -------------------------------------------------------------------------
    // answerCallbackQuery()
    // -------------------------------------------------------------------------

    public function testAnswerCallbackQueryPostsToCorrectTelegramApiUrl(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $service = new MessagingService;
        $service->answerCallbackQuery('my-bot-token', 'query-id-abc');

        Http::assertSent(
            fn ($request) => $request->url() === 'https://api.telegram.org/botmy-bot-token/answerCallbackQuery'
        );
    }

    public function testAnswerCallbackQueryIncludesCallbackQueryId(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $service = new MessagingService;
        $service->answerCallbackQuery('my-bot-token', 'query-id-abc');

        Http::assertSent(
            fn ($request) => $request['callback_query_id'] === 'query-id-abc' && $request['text'] === ''
        );
    }

    public function testAnswerCallbackQueryIncludesOptionalText(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $service = new MessagingService;
        $service->answerCallbackQuery('my-bot-token', 'query-id-abc', 'Done!');

        Http::assertSent(
            fn ($request) => $request['text'] === 'Done!'
        );
    }

    public function testAnswerCallbackQueryReturnsTrueOnSuccess(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $service = new MessagingService;
        $result = $service->answerCallbackQuery('my-bot-token', 'query-id-abc');

        $this->assertTrue($result);
    }

    public function testAnswerCallbackQueryReturnsFalseOnApiError(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false], 400)]);

        $service = new MessagingService;
        $result = $service->answerCallbackQuery('my-bot-token', 'query-id-abc');

        $this->assertFalse($result);
    }
}
