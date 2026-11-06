<?php

namespace WHMCS\Module\Notification\Telegram;

use WHMCS\Module\Notification\DescriptionTrait;
use WHMCS\Module\Contracts\NotificationModuleInterface;
use WHMCS\Notification\Contracts\NotificationInterface;

class Telegram implements NotificationModuleInterface
{
    private const MAX_MESSAGE_LENGTH = 4096;

    use DescriptionTrait;

    protected $client;

    public function __construct()
    {
        $this->setDisplayName('Telegram')
            ->setLogoFileName('logo.png');

        if (!class_exists(\GuzzleHttp\Client::class)) {
            throw new \RuntimeException(
                'GuzzleHttp\Client is unavailable. Ensure the WHMCS Composer autoloader is loaded and Guzzle is installed.'
            );
        }

        $this->client = new \GuzzleHttp\Client([
            'base_uri' => 'https://api.telegram.org/',
            'timeout' => 10,
        ]);
    }

    public function settings()
    {
        return [
            'botToken' => [
                'FriendlyName' => 'Telegram Bot API Token',
                'Type' => 'text',
                'Description' => 'You can create token at <a target="_blank" href="https://t.me/BotFather">BotFather</a>',
            ],
        ];
    }

    public function testConnection($settings)
    {
        if (!isset($settings['botToken']) || !is_string($settings['botToken'])
            || strpos($settings['botToken'], ':') === false) {
            throw new \Exception('Token is empty or invalid, please check and try again.');
        }

        $response = $this->client->get("/bot{$settings['botToken']}/getMe", [
            'http_errors' => false,
        ]);
        $this->assertTelegramSuccess($response, 'Telegram connection test');
    }

    public function notificationSettings()
    {
        return [
            'chatID' => [
                'FriendlyName' => 'Telegram Chat ID',
                'Type' => 'text',
                'Description' => 'You can get it from @GetIDsBot or @RawDataBot',
                'Required' => true,
            ],
            'topicGroup' => [
                'FriendlyName' => 'TopicID',
                'Type' => 'text',
                'Description' => 'Topic of the Group.',
                'Placeholder' => ' ',
            ],
        ];
    }

    public function getDynamicField($fieldName, $settings)
    {
        return [];
    }


    public function sendNotification(NotificationInterface $notification, $moduleSettings, $notificationSettings)
    {
        if (!isset($moduleSettings['botToken']) || !is_string($moduleSettings['botToken'])
            || $moduleSettings['botToken'] === '') {
            throw new \Exception('No bot token for notification delivery.');
        }

        if (!isset($notificationSettings['chatID'])
            || !is_scalar($notificationSettings['chatID'])
            || trim((string) $notificationSettings['chatID']) === '') {
            throw new \Exception('No chat ID for notification delivery.');
        }

        $topicGroup = $notificationSettings['topicGroup'] ?? '';
        if ($topicGroup !== '' && $topicGroup !== null) {
            if (!is_scalar($topicGroup)
                || !preg_match('/^[1-9][0-9]*$/D', (string) $topicGroup)) {
                throw new \Exception('Topic ID must be a positive integer.');
            }
        }

        $chunks = $this->buildMessageChunks(
            (string) $notification->getTitle(),
            (string) $notification->getMessage(),
            (string) $notification->getUrl()
        );

        foreach ($chunks as $index => $text) {
            $data = [
                'chat_id' => (string) $notificationSettings['chatID'],
                'text' => $text,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ];
            if ($topicGroup !== '' && $topicGroup !== null) {
                $data['message_thread_id'] = (string) $topicGroup;
            }

            $response = $this->client->post("/bot{$moduleSettings['botToken']}/sendMessage", [
                'http_errors' => false,
                'form_params' => $data,
            ]);

            $this->assertTelegramSuccess(
                $response,
                'Sending Telegram message chunk ' . ($index + 1) . ' of ' . count($chunks)
            );
        }
    }

    private function assertTelegramSuccess($response, $operation)
    {
        $statusCode = $response->getStatusCode();
        $body = (string) $response->getBody();
        $result = json_decode($body, true);

        if ($statusCode !== 200 || !is_array($result) || empty($result['ok'])) {
            $description = is_array($result) && !empty($result['description'])
                ? $result['description']
                : 'Telegram returned HTTP ' . $statusCode . ' with an invalid or unsuccessful response.';
            throw new \RuntimeException($operation . ' failed: ' . $description);
        }
    }

    private function buildMessageChunks($title, $message, $url)
    {
        $segments = [
            ['text' => $title, 'bold' => true],
            ['text' => "\n\n", 'bold' => false],
            ['text' => $message, 'bold' => false],
            ['text' => "\n\n", 'bold' => false],
            ['text' => $url, 'bold' => false],
        ];
        $chunks = [];
        $chunk = '';
        $length = 0;
        $bold = false;

        foreach ($segments as $segment) {
            if ($segment['bold'] !== $bold) {
                $chunk .= $segment['bold'] ? '<b>' : '</b>';
                $bold = $segment['bold'];
            }

            $characters = preg_split('//u', $segment['text'], -1, PREG_SPLIT_NO_EMPTY);
            if ($characters === false) {
                throw new \RuntimeException('Notification text must be valid UTF-8.');
            }

            foreach ($characters as $character) {
                if ($length === self::MAX_MESSAGE_LENGTH) {
                    if ($bold) {
                        $chunk .= '</b>';
                    }
                    $chunks[] = $chunk;
                    $chunk = $bold ? '<b>' : '';
                    $length = 0;
                }

                $chunk .= htmlspecialchars($character, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $length++;
            }
        }

        if ($bold) {
            $chunk .= '</b>';
        }
        if ($chunk !== '') {
            $chunks[] = $chunk;
        }

        return $chunks;
    }
}
