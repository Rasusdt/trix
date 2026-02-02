<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\User;
use App\Setting;
use App\Payment;
use App\Promocode;
use App\Broadcast;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TelegramController extends Controller
{
    private $token;
    private $apiUrl;
    private $channelId;
    private $settings;
    
    // Constants from env
    private $minDepositNotify;
    private $adminIds;
    
    private const DAILY_BONUS_SECONDS = 86400;    // 24 hours in seconds
    private const BROADCAST_CHUNK_SIZE = 100;     // Broadcast chunk size
    private const BROADCAST_DELAY_MS = 35000;     // Delay between messages (35ms)
    private const CACHE_TTL = 3600;               // Settings cache TTL (1 hour)
    private const RATE_LIMIT_SECONDS = 1;         // Min interval between commands
    private const RATE_LIMIT_MAX = 30;            // Max commands per minute

    public function __construct()
    {
        $this->settings = $this->getSettings();
        $this->token = env('TELEGRAM_TOKEN', $this->settings->telegram_token ?? '');
        $this->apiUrl = "https://api.telegram.org/bot{$this->token}";
        $this->channelId = env('TELEGRAM_CHANNEL_ID', '@valuba_casino');
        $this->minDepositNotify = env('TELEGRAM_MIN_DEPOSIT_NOTIFY', 5000);
        $this->adminIds = array_map('trim', explode(',', env('TELEGRAM_ADMIN_IDS', '')));
    }
    
    /**
     * Rate limiting - spam protection
     */
    private function checkRateLimit($tgUserId)
    {
        $key = "tg_rate_{$tgUserId}";
        $countKey = "tg_count_{$tgUserId}";
        
        // Check minimum interval
        $lastRequest = Cache::get($key);
        if ($lastRequest && (time() - $lastRequest) < self::RATE_LIMIT_SECONDS) {
            return false;
        }
        
        // Check requests per minute
        $count = Cache::get($countKey, 0);
        if ($count >= self::RATE_LIMIT_MAX) {
            return false;
        }
        
        // Update counters
        Cache::put($key, time(), 60);
        Cache::put($countKey, $count + 1, 60);
        
        return true;
    }
    
    /**
     * Get user by TG ID or return error
     */
    private function requireUser($tgUserId, $chatId)
    {
        $user = User::where('tg_id', $tgUserId)->first();
        if (!$user) {
            $this->sendMessage($chatId, "❌ Аккаунт не привязан к Telegram.\n\nОтправь /start для привязки.");
            return null;
        }
        return $user;
    }
    
    /**
     * Validate numeric ID
     */
    private function validateNumericId($value, $chatId, $fieldName = 'ID')
    {
        if (!is_numeric($value) || $value <= 0) {
            $this->sendMessage($chatId, "❌ {$fieldName} должен быть положительным числом");
            return false;
        }
        return true;
    }
    
    /**
     * Валидация суммы
     */
    private function validateAmount($value, $chatId, $max = 1000000)
    {
        if (!is_numeric($value)) {
            $this->sendMessage($chatId, "❌ Сумма должна быть числом");
            return false;
        }
        $amount = floatval($value);
        if ($amount <= 0 || $amount > $max) {
            $this->sendMessage($chatId, "❌ Сумма должна быть от 0 до {$max}");
            return false;
        }
        return true;
    }
    
    /**
     * Получение домена сайта
     */
    private function getDomain()
    {
        return env('APP_DOMAIN', 'golden1x.ru');
    }
    
    /**
     * Получение полного URL сайта
     */
    private function getSiteUrl()
    {
        return 'https://' . $this->getDomain();
    }
    
    /**
     * Получение настроек с кэшированием
     */
    private function getSettings()
    {
        return Cache::remember('telegram_settings', self::CACHE_TTL, function () {
            return Setting::find(1);
        });
    }
    
    /**
     * Получить список админов из .env
     */
    private static function getAdminIds()
    {
        $ids = env('TELEGRAM_ADMIN_IDS', '5538762974,7020554392');
        return array_filter(array_map('intval', explode(',', $ids)));
    }
    
    /**
     * Проверка является ли пользователь админом
     */
    private function isAdmin($tgUserId)
    {
        return in_array($tgUserId, self::getAdminIds());
    }

    /**
     * Проверка подписки пользователя на канал
     */
    private function checkSubscription($tgUserId)
    {
        $result = $this->apiRequest('getChatMember', [
            'chat_id' => $this->channelId,
            'user_id' => $tgUserId
        ]);

        if (!$result || !isset($result['ok']) || !$result['ok']) {
            return false;
        }

        $status = $result['result']['status'] ?? '';
        return in_array($status, ['member', 'administrator', 'creator']);
    }

    /**
     * Webhook handler - receives all messages from Telegram
     */
    public function webhook(Request $request)
    {
        // Check request is from Telegram (by secret token)
        $secretToken = $request->header('X-Telegram-Bot-Api-Secret-Token');
        $expectedToken = env('TELEGRAM_WEBHOOK_SECRET');
        
        // Reject if no secret configured or token mismatch
        if (!$expectedToken || $secretToken !== $expectedToken) {
            Log::warning('Invalid webhook secret token', [
                'ip' => $request->ip(),
                'received' => $secretToken
            ]);
            return response()->json(['ok' => false], 403);
        }

        $update = $request->all();
        
        Log::info('Telegram webhook received', $update);

        // Handle callback query (buttons)
        if (isset($update['callback_query'])) {
            return $this->handleCallback($update['callback_query']);
        }

        // Handle regular messages
        if (isset($update['message'])) {
            return $this->handleMessage($update['message']);
        }

        return response()->json(['ok' => true]);
    }


    /**
     * Обработка текстовых сообщений и команд
     */
    private function handleMessage($message)
    {
        $chatId = $message['chat']['id'];
        $text = $message['text'] ?? '';
        $tgUserId = $message['from']['id'];

        // Rate limiting (кроме админов)
        if (!$this->isAdmin($tgUserId) && !$this->checkRateLimit($tgUserId)) {
            return response()->json(['ok' => true]); // Молча игнорируем спам
        }

        // Проверка бана (кроме /start для новых юзеров)
        if (strpos($text, '/start') !== 0) {
            $user = User::where('tg_id', $tgUserId)->first();
            if ($user && $user->ban) {
                return $this->sendMessage($chatId, "🚫 Ваш аккаунт заблокирован. Обратитесь в поддержку.");
            }
        }

        // Команды
        if (strpos($text, '/start') === 0) {
            return $this->cmdStart($chatId, $tgUserId, $text);
        }

        if ($text === '/balance' || strpos($text, 'Баланс') !== false) {
            return $this->cmdBalance($chatId, $tgUserId);
        }

        if ($text === '/bonus' || strpos($text, 'Бонус') !== false) {
            return $this->cmdBonus($chatId, $tgUserId);
        }

        if ($text === '/deposit' || strpos($text, 'Пополнить') !== false) {
            return $this->cmdDeposit($chatId, $tgUserId);
        }

        if ($text === '/ref' || strpos($text, 'Рефералы') !== false) {
            return $this->cmdRef($chatId, $tgUserId);
        }

        if ($text === '/help' || strpos($text, 'Помощь') !== false) {
            return $this->cmdHelp($chatId);
        }

        if ($text === '/stats' || strpos($text, 'Статистика') !== false) {
            return $this->cmdStats($chatId, $tgUserId);
        }

        if ($text === '/domain' || strpos($text, 'Домен') !== false) {
            return $this->cmdDomain($chatId);
        }

        if ($text === '/info') {
            return $this->cmdInfo($chatId);
        }

        // Админ команды
        if ($this->isAdmin($tgUserId)) {
            // Логируем все админ-команды
            Log::info('TG ADMIN COMMAND', [
                'admin_tg_id' => $tgUserId,
                'command' => $text,
                'time' => date('Y-m-d H:i:s')
            ]);

            if ($text === '/admin' || $text === '🔐 Админка') {
                return $this->cmdAdmin($chatId);
            }
            
            if (strpos($text, '/addbal ') === 0) {
                return $this->cmdAddBalance($chatId, $text);
            }
            
            if (strpos($text, '/setbal ') === 0) {
                return $this->cmdSetBalance($chatId, $text);
            }
            
            if (strpos($text, '/userinfo ') === 0) {
                return $this->cmdUserInfo($chatId, $text);
            }
            
            if ($text === '/allusers') {
                return $this->cmdAllUsers($chatId);
            }
            
            if ($text === '/todaystats') {
                return $this->cmdTodayStats($chatId);
            }
            
            if (strpos($text, '/broadcast ') === 0) {
                return $this->cmdBroadcast($chatId, $text);
            }
            
            if (strpos($text, '/promo ') === 0) {
                return $this->cmdCreatePromo($chatId, $text);
            }
            
            if ($text === '🎁 Промокод') {
                $help = "🎁 *Создание промокода*\n\n";
                $help .= "`/promo КОД сумма активаций [вагер] [тип]`\n\n";
                $help .= "*Примеры:*\n";
                $help .= "`/promo BONUS100 100 50` - 100₽ на баланс\n";
                $help .= "`/promo BONUS100 100 50 3` - с вагером x3\n";
                $help .= "`/promo DEP50 50 100 5 deposit` - +50% к депозиту\n\n";
                $help .= "*Типы:* balance, deposit";
                return $this->sendMessage($chatId, $help, 'Markdown');
            }
            
            if (strpos($text, '/ban ') === 0) {
                return $this->cmdBan($chatId, $text);
            }
            
            if (strpos($text, '/unban ') === 0) {
                return $this->cmdUnban($chatId, $text);
            }
            
            if (strpos($text, '/find ') === 0) {
                return $this->cmdFind($chatId, $text);
            }
            
            if ($text === '📊 Статистика дня') {
                return $this->cmdTodayStats($chatId);
            }
            
            if ($text === '👥 Топ юзеров') {
                return $this->cmdAllUsers($chatId);
            }
            
            if ($text === '📢 Рассылка') {
                return $this->sendMessage($chatId, "📢 *Рассылка*\n\nИспользуй команду:\n`/broadcast текст сообщения`", 'Markdown');
            }
        }

        // Проверка кода привязки (8 символов)
        if (preg_match('/^[A-Z0-9]{8}$/', $text)) {
            return $this->linkAccount($chatId, $tgUserId, $text);
        }

        // Неизвестная команда - показываем помощь
        return $this->sendMessage($chatId, "❓ Неизвестная команда. Нажми /help для списка команд.");
    }

    /**
     * /start - Приветствие и привязка аккаунта
     */
    private function cmdStart($chatId, $tgUserId, $text)
    {
        $settings = Setting::find(1);
        $tgChannel = $settings->tg_channel ?? 'https://t.me/valuba_casino';

        // Проверяем есть ли код привязки в /start
        $parts = explode(' ', $text);
        $linkCode = $parts[1] ?? null;

        $user = User::where('tg_id', $tgUserId)->first();

        if ($user) {
            // Уже привязан - показываем меню
            $isSubscribed = $this->checkSubscription($tgUserId);
            
            $message = "👋 Привет, {$user->username}!\n\n";
            $message .= "💰 Баланс: {$user->balance} ₽\n";
            $message .= "🎁 Бонусный: {$user->bonus_balance} ₽\n\n";
            
            if (!$isSubscribed) {
                $message .= "⚠️ Подпишись на канал для получения бонусов:\n{$tgChannel}\n\n";
            } else {
                $message .= "✅ Подписка на канал активна\n\n";
            }
            
            $message .= "Выбери действие:";
            
            return $this->sendMessageWithKeyboard($chatId, $message, $this->getMainKeyboard());
        }

        if ($linkCode) {
            // Пробуем привязать по коду
            return $this->linkAccount($chatId, $tgUserId, $linkCode);
        }

        // Новый пользователь без кода
        $message = "👋 Добро пожаловать в Golden1x Bot!\n\n";
        $message .= "📢 Сначала подпишись на наш канал:\n{$tgChannel}\n\n";
        $message .= "Чтобы привязать аккаунт:\n";
        $message .= "1️⃣ Зайди на сайт " . $this->getDomain() . "\n";
        $message .= "2️⃣ Открой профиль\n";
        $message .= "3️⃣ Нажми 'Получить код привязки'\n";
        $message .= "4️⃣ Отправь код сюда\n\n";
        $message .= "🌐 Сайт: " . $this->getDomain();

        return $this->sendMessage($chatId, $message);
    }

    /**
     * Привязка аккаунта по коду
     */
    private function linkAccount($chatId, $tgUserId, $code)
    {
        $settings = Setting::find(1);
        $tgChannel = $settings->tg_channel ?? 'https://t.me/valuba_casino';

        // Проверяем подписку на канал
        $isSubscribed = $this->checkSubscription($tgUserId);
        
        if (!$isSubscribed) {
            $message = "❌ Для привязки аккаунта нужно подписаться на канал!\n\n";
            $message .= "📢 Подпишись: {$tgChannel}\n\n";
            $message .= "После подписки отправь код ещё раз.";
            return $this->sendMessage($chatId, $message);
        }

        // Ищем юзера с таким кодом привязки
        $user = User::where('telegram_link_code', $code)
            ->where('tg_id', '0')
            ->first();

        if (!$user) {
            return $this->sendMessage($chatId, "❌ Неверный код или аккаунт уже привязан.\n\nПолучи новый код в профиле на сайте.");
        }

        // Привязываем
        $user->tg_id = $tgUserId;
        $user->telegram_link_code = null;
        $user->save();

        $message = "✅ Аккаунт успешно привязан!\n\n";
        $message .= "👤 {$user->username}\n";
        $message .= "💰 Баланс: {$user->balance} ₽\n\n";
        $message .= "Теперь ты будешь получать уведомления о выигрышах и бонусах!";

        return $this->sendMessageWithKeyboard($chatId, $message, $this->getMainKeyboard());
    }

    /**
     * /balance - Show balance
     */
    private function cmdBalance($chatId, $tgUserId)
    {
        if (!$user = $this->requireUser($tgUserId, $chatId)) {
            return response()->json(['ok' => true]);
        }

        $message = "💰 *Твой баланс*\n\n";
        $message .= "Основной: *{$user->balance} ₽*\n";
        $message .= "Бонусный: *{$user->bonus_balance} ₽*\n";
        $message .= "Реферальный: *{$user->referral_balance} ₽*\n";
        $message .= "Кэшбек: *{$user->cashback_balance} ₽*\n\n";
        $message .= "🎰 [Играть на сайте](" . $this->getSiteUrl() . ")";

        return $this->sendMessage($chatId, $message, 'Markdown');
    }


    /**
     * /bonus - Bonus info (requires subscription)
     */
    private function cmdBonus($chatId, $tgUserId)
    {
        if (!$user = $this->requireUser($tgUserId, $chatId)) {
            return response()->json(['ok' => true]);
        }

        $settings = Setting::find(1);
        $tgChannel = $settings->tg_channel ?? 'https://t.me/valuba_casino';

        // Check channel subscription
        $isSubscribed = $this->checkSubscription($tgUserId);
        
        if (!$isSubscribed) {
            $message = "❌ *Для получения бонусов нужна подписка на канал!*\n\n";
            $message .= "📢 Подпишись: {$tgChannel}\n\n";
            $message .= "После подписки нажми /bonus ещё раз.";
            return $this->sendMessage($chatId, $message, 'Markdown');
        }

        $dailyAvailable = (time() - $user->bonus_daily) > 86400;

        $message = "🎁 *Бонусы*\n\n";
        $message .= "✅ Подписка на канал активна!\n\n";
        
        if ($dailyAvailable) {
            $message .= "✅ Ежедневный бонус доступен!\n";
        } else {
            $timeLeft = 86400 - (time() - $user->bonus_daily);
            $hours = floor($timeLeft / 3600);
            $mins = floor(($timeLeft % 3600) / 60);
            $message .= "⏳ Ежедневный через: {$hours}ч {$mins}м\n";
        }

        $message .= "\n💡 Забрать бонус можно на сайте в разделе 'Бонус'";
        $message .= "\n\n🔗 [Забрать бонус](" . $this->getSiteUrl() . "/bonus)";

        return $this->sendMessage($chatId, $message, 'Markdown');
    }

    /**
     * /deposit - Deposit link
     */
    private function cmdDeposit($chatId, $tgUserId)
    {
        if (!$user = $this->requireUser($tgUserId, $chatId)) {
            return response()->json(['ok' => true]);
        }

        $message = "💳 *Пополнение баланса*\n\n";
        $message .= "Доступные способы:\n";
        $message .= "• Банковская карта\n";
        $message .= "• QIWI\n";
        $message .= "• FK Wallet\n";
        $message .= "• TON (криптовалюта)\n\n";
        $message .= "Минимальная сумма: 100 ₽\n\n";
        $message .= "🔗 [Пополнить баланс](" . $this->getSiteUrl() . "/pay)";

        return $this->sendMessage($chatId, $message, 'Markdown');
    }

    /**
     * /ref - Referral program
     */
    private function cmdRef($chatId, $tgUserId)
    {
        if (!$user = $this->requireUser($tgUserId, $chatId)) {
            return response()->json(['ok' => true]);
        }

        $refLink = $this->getSiteUrl() . "/r/{$user->unique_id}";
        $refCount = User::where('referral_use', $user->id)->count();

        $message = "👥 *Реферальная программа*\n\n";
        $message .= "Твоя ссылка:\n`{$refLink}`\n\n";
        $message .= "📊 Статистика:\n";
        $message .= "• Рефералов: {$refCount}\n";
        $message .= "• Заработано: {$user->referral_balance} ₽\n\n";
        $message .= "💰 Получай 15% от депозитов рефералов!";

        return $this->sendMessage($chatId, $message, 'Markdown');
    }

    /**
     * /stats - Game statistics
     */
    private function cmdStats($chatId, $tgUserId)
    {
        if (!$user = $this->requireUser($tgUserId, $chatId)) {
            return response()->json(['ok' => true]);
        }

        $totalDeposits = Payment::where('user_id', $user->id)->where('status', 1)->sum('sum');

        $message = "📊 *Твоя статистика*\n\n";
        $message .= "🎲 Dice: {$user->dice} ₽\n";
        $message .= "💣 Mines: {$user->mines} ₽\n";
        $message .= "🎡 Wheel: {$user->wheel} ₽\n";
        $message .= "🎰 Slots: {$user->slots} ₽\n";
        $message .= "🫧 Bubbles: {$user->bubbles} ₽\n\n";
        $message .= "💵 Всего депозитов: {$totalDeposits} ₽\n";
        $message .= "🎯 Всего ставок: {$user->bets} ₽";

        return $this->sendMessage($chatId, $message, 'Markdown');
    }

    /**
     * /help - Commands list
     */
    private function cmdHelp($chatId)
    {
        $settings = Setting::find(1);
        $tgChannel = $settings->tg_channel ?? 'https://t.me/valuba_casino';

        $message = "❓ Доступные команды\n\n";
        $message .= "/start - Начать / Привязать аккаунт\n";
        $message .= "/balance - Проверить баланс\n";
        $message .= "/bonus - Информация о бонусах\n";
        $message .= "/deposit - Пополнить баланс\n";
        $message .= "/ref - Реферальная программа\n";
        $message .= "/stats - Статистика игр\n";
        $message .= "/domain - Актуальный домен\n";
        $message .= "/info - Информация о боте\n";
        $message .= "/help - Эта справка\n\n";
        $message .= "📢 Канал: " . $tgChannel . "\n";
        $message .= "🌐 Сайт: " . $this->getDomain();

        return $this->sendMessage($chatId, $message);
    }

    /**
     * /domain - Актуальный домен сайта
     */
    private function cmdDomain($chatId)
    {
        $domain = $this->settings->referral_domain ?? $this->getDomain();
        $domain = str_replace(['https://', 'http://'], '', $domain);
        
        $message = "🌐 *Актуальный домен*\n\n";
        $message .= "Сайт: *{$domain}*\n\n";
        $message .= "🔗 https://{$domain}\n\n";
        $message .= "💡 Сохрани эту ссылку!";

        return $this->sendMessage($chatId, $message, 'Markdown');
    }

    /**
     * /info - Информация о боте
     */
    private function cmdInfo($chatId)
    {
        $domain = $this->settings->referral_domain ?? $this->getDomain();
        $domain = str_replace(['https://', 'http://'], '', $domain);
        $tgChannel = $this->settings->tg_channel ?? 'https://t.me/valuba_casino';

        $message = "ℹ️ Информация о боте\n\n";
        $message .= "🎰 Официальный бот казино Golden1x\n\n";
        $message .= "📌 Возможности:\n";
        $message .= "• Проверка баланса\n";
        $message .= "• Информация о бонусах\n";
        $message .= "• Уведомления о выигрышах\n";
        $message .= "• Реферальная программа\n";
        $message .= "• Статистика игр\n\n";
        $message .= "🌐 Сайт: {$domain}\n";
        $message .= "📢 Канал: {$tgChannel}\n\n";
        $message .= "Напиши /help для списка команд";

        return $this->sendMessage($chatId, $message);
    }


    /**
     * Обработка callback кнопок
     */
    private function handleCallback($callback)
    {
        $chatId = $callback['message']['chat']['id'];
        $data = $callback['data'];
        $tgUserId = $callback['from']['id'];

        $this->answerCallback($callback['id']);

        // Обработка рассылки промокода (только для админов)
        if (strpos($data, 'broadcast_promo_') === 0 && $this->isAdmin($tgUserId)) {
            $promoCode = str_replace('broadcast_promo_', '', $data);
            return $this->broadcastPromo($chatId, $promoCode);
        }

        if ($data === 'cancel_broadcast') {
            return $this->sendMessage($chatId, "❌ Рассылка отменена");
        }

        switch ($data) {
            case 'balance':
                return $this->cmdBalance($chatId, $tgUserId);
            case 'bonus':
                return $this->cmdBonus($chatId, $tgUserId);
            case 'deposit':
                return $this->cmdDeposit($chatId, $tgUserId);
            case 'ref':
                return $this->cmdRef($chatId, $tgUserId);
            case 'stats':
                return $this->cmdStats($chatId, $tgUserId);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Главная клавиатура
     */
    private function getMainKeyboard()
    {
        return [
            'keyboard' => [
                [['text' => '💰 Баланс'], ['text' => '🎁 Бонус']],
                [['text' => '💳 Пополнить'], ['text' => '👥 Рефералы']],
                [['text' => '📊 Статистика'], ['text' => '🌐 Домен']],
                [['text' => '❓ Помощь']]
            ],
            'resize_keyboard' => true
        ];
    }

    /**
     * Отправка сообщения
     */
    private function sendMessage($chatId, $text, $parseMode = null)
    {
        $data = [
            'chat_id' => $chatId,
            'text' => $text,
            'disable_web_page_preview' => true
        ];

        if ($parseMode) {
            $data['parse_mode'] = $parseMode;
        }

        $this->apiRequest('sendMessage', $data);
        return response()->json(['ok' => true]);
    }

    /**
     * Отправка сообщения с клавиатурой
     */
    private function sendMessageWithKeyboard($chatId, $text, $keyboard, $parseMode = null)
    {
        $data = [
            'chat_id' => $chatId,
            'text' => $text,
            'reply_markup' => json_encode($keyboard)
        ];
        
        if ($parseMode) {
            $data['parse_mode'] = $parseMode;
        }

        $this->apiRequest('sendMessage', $data);
        return response()->json(['ok' => true]);
    }

    /**
     * Отправка фото с подписью
     */
    private function sendPhoto($chatId, $photoPath, $caption = null, $parseMode = 'HTML')
    {
        $url = "{$this->apiUrl}/sendPhoto";
        
        $data = [
            'chat_id' => $chatId,
        ];
        
        if ($caption) {
            $data['caption'] = $caption;
            $data['parse_mode'] = $parseMode;
        }
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, array_merge($data, [
            'photo' => new \CURLFile($photoPath)
        ]));
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        return json_decode($response, true);
    }

    /**
     * Ответ на callback
     */
    private function answerCallback($callbackId)
    {
        $this->apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId]);
    }

    /**
     * API запрос к Telegram с retry логикой
     */
    private function apiRequest($method, $data, $retries = 3)
    {
        $url = "{$this->apiUrl}/{$method}";
        
        for ($attempt = 1; $attempt <= $retries; $attempt++) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            
            $result = curl_exec($ch);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($error) {
                Log::error("Telegram API curl error (attempt {$attempt})", ['method' => $method, 'error' => $error]);
                if ($attempt < $retries) {
                    usleep(500000 * $attempt); // Exponential backoff: 0.5s, 1s, 1.5s
                    continue;
                }
                return ['ok' => false, 'error' => $error];
            }
            
            $decoded = json_decode($result, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error("Telegram API JSON decode error", ['method' => $method, 'response' => $result]);
                return ['ok' => false, 'error' => 'JSON decode error'];
            }
            
            // Успешный ответ или ошибка не связанная с сетью
            if (isset($decoded['ok'])) {
                if (!$decoded['ok']) {
                    Log::warning("Telegram API error", ['method' => $method, 'response' => $decoded]);
                }
                return $decoded;
            }
            
            // Неожиданный ответ - retry
            if ($attempt < $retries) {
                usleep(500000 * $attempt);
            }
        }
        
        return ['ok' => false, 'error' => 'Max retries exceeded'];
    }

    /**
     * Уведомление всех админов
     */
    public static function notifyAdmins($message)
    {
        foreach (self::getAdminIds() as $adminId) {
            self::notify($adminId, $message);
        }
    }

    /**
     * Уведомление о крупном депозите (>= MIN_DEPOSIT_NOTIFY)
     */
    public static function notifyDeposit($userId, $username, $amount, $method = 'unknown')
    {
        $minNotify = env('TELEGRAM_MIN_DEPOSIT_NOTIFY', 5000);
        if ($amount < $minNotify) return;
        
        $message = "💰 *КРУПНЫЙ ДЕПОЗИТ!*\n\n";
        $message .= "👤 Юзер: {$username} (ID: {$userId})\n";
        $message .= "💵 Сумма: *{$amount} ₽*\n";
        $message .= "💳 Метод: {$method}\n";
        $message .= "🕐 Время: " . date('d.m.Y H:i:s');
        
        self::notifyAdmins($message);
    }

    /**
     * Уведомление о новой заявке на вывод
     */
    public static function notifyWithdraw($withdrawId, $userId, $username, $amount, $wallet, $system)
    {
        $systemNames = [
            'qiwi' => 'QIWI',
            'fkwallet' => 'FK Wallet', 
            'sbp' => 'СБП',
            'card' => 'Карта',
            'trc20' => 'TRC20 USDT'
        ];
        
        $systemName = $systemNames[$system] ?? $system;
        
        $message = "🔔 *НОВАЯ ЗАЯВКА НА ВЫВОД*\n\n";
        $message .= "🆔 Заявка: #{$withdrawId}\n";
        $message .= "👤 Юзер: {$username} (ID: {$userId})\n";
        $message .= "💵 Сумма: *{$amount} ₽*\n";
        $message .= "💳 Система: {$systemName}\n";
        $message .= "📝 Кошелек: `{$wallet}`\n";
        $message .= "🕐 Время: " . date('d.m.Y H:i:s') . "\n\n";
        $message .= "📊 [Открыть админку](https://" . env('APP_DOMAIN', 'golden1x.ru') . "/admin/withdraws)";
        
        self::notifyAdmins($message);
    }

    /**
     * Статический метод для отправки уведомлений
     */
    public static function notify($tgId, $message)
    {
        if (!$tgId || $tgId == '0') return false;

        $settings = Setting::find(1);
        $token = $settings->telegram_token ?? env('TELEGRAM_TOKEN');
        
        $url = "https://api.telegram.org/bot{$token}/sendMessage";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, [
            'chat_id' => $tgId,
            'text' => $message,
            'parse_mode' => 'Markdown'
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        
        curl_exec($ch);
        curl_close($ch);
        
        return true;
    }

    /**
     * Установка webhook
     */
    /**
     * Set webhook
     */
    public function setWebhook()
    {
        $webhookUrl = 'https://' . env('APP_DOMAIN', 'golden1x.ru') . '/telegram/webhook';
        $secretToken = env('TELEGRAM_WEBHOOK_SECRET');
        
        if (!$secretToken) {
            return response()->json(['ok' => false, 'error' => 'TELEGRAM_WEBHOOK_SECRET not configured in .env']);
        }
        
        $result = $this->apiRequest('setWebhook', [
            'url' => $webhookUrl,
            'allowed_updates' => json_encode(['message', 'callback_query']),
            'secret_token' => $secretToken
        ]);

        return response()->json($result);
    }

    /**
     * Delete webhook
     */
    public function deleteWebhook()
    {
        $result = $this->apiRequest('deleteWebhook', []);
        return response()->json($result);
    }

    /**
     * Информация о webhook
     */
    public function getWebhookInfo()
    {
        $result = $this->apiRequest('getWebhookInfo', []);
        return response()->json($result);
    }

    // Админ функции

    /**
     * /admin - Админ панель
     */
    private function cmdAdmin($chatId)
    {
        $totalUsers = User::count();
        $todayUsers = User::whereDate('created_at', today())->count();
        $totalBalance = User::sum('balance');
        $usersWithTg = User::where('tg_id', '!=', '0')->whereNotNull('tg_id')->count();
        $activePromos = Promocode::where('activation', '>', 0)->count();
        
        $message = "🔐 *АДМИН ПАНЕЛЬ*\n\n";
        $message .= "📊 *Статистика:*\n";
        $message .= "👥 Всего юзеров: {$totalUsers}\n";
        $message .= "📱 С привязкой TG: {$usersWithTg}\n";
        $message .= "🆕 Сегодня: {$todayUsers}\n";
        $message .= "💰 Общий баланс: {$totalBalance} ₽\n";
        $message .= "🎁 Активных промо: {$activePromos}\n\n";
        
        $message .= "📝 *Команды:*\n";
        $message .= "`/userinfo ID` - инфо о юзере\n";
        $message .= "`/addbal ID сумма` - добавить баланс\n";
        $message .= "`/setbal ID сумма` - установить баланс\n";
        $message .= "`/promo КОД сумма кол-во` - создать промо\n";
        $message .= "`/ban ID` - забанить юзера\n";
        $message .= "`/unban ID` - разбанить юзера\n";
        $message .= "`/allusers` - топ 20 юзеров\n";
        $message .= "`/todaystats` - статистика за день\n";
        $message .= "`/broadcast текст` - рассылка всем\n";
        $message .= "`/find ник` - поиск юзера\n";

        return $this->sendMessageWithKeyboard($chatId, $message, $this->getAdminKeyboard(), 'Markdown');
    }

    /**
     * Админ клавиатура
     */
    private function getAdminKeyboard()
    {
        return [
            'keyboard' => [
                [['text' => '📊 Статистика дня'], ['text' => '👥 Топ юзеров']],
                [['text' => '📢 Рассылка'], ['text' => '🎁 Промокод']],
                [['text' => '🔐 Админка'], ['text' => '❓ Помощь']]
            ],
            'resize_keyboard' => true
        ];
    }

    /**
     * /userinfo ID - Информация о пользователе
     */
    private function cmdUserInfo($chatId, $text)
    {
        $parts = explode(' ', $text);
        $userId = $parts[1] ?? null;

        if (!$userId) {
            return $this->sendMessage($chatId, "❌ Использование: /userinfo ID");
        }

        $user = User::find($userId);
        if (!$user) {
            // Попробуем найти по username
            $user = User::where('username', 'LIKE', "%{$userId}%")->first();
        }

        if (!$user) {
            return $this->sendMessage($chatId, "❌ Пользователь не найден");
        }

        $totalDeposits = Payment::where('user_id', $user->id)->where('status', 1)->sum('sum');
        $refCount = User::where('referral_use', $user->id)->count();

        $message = "👤 *Информация о пользователе*\n\n";
        $message .= "🆔 ID: `{$user->id}`\n";
        $message .= "👤 Ник: {$user->username}\n";
        $message .= "📱 TG ID: `{$user->tg_id}`\n";
        $message .= "🔗 VK ID: {$user->vk_id}\n\n";
        
        $message .= "💰 *Балансы:*\n";
        $message .= "Основной: {$user->balance} ₽\n";
        $message .= "Бонусный: {$user->bonus_balance} ₽\n";
        $message .= "Реферальный: {$user->referral_balance} ₽\n\n";
        
        $message .= "📊 *Статистика:*\n";
        $message .= "Депозитов: {$totalDeposits} ₽\n";
        $message .= "Ставок: {$user->bets} ₽\n";
        $message .= "Рефералов: {$refCount}\n\n";
        
        $message .= "📅 Регистрация: {$user->created_at}\n";
        $message .= "🚫 Бан: " . ($user->ban ? "Да" : "Нет");

        return $this->sendMessage($chatId, $message, 'Markdown');
    }

    /**
     * /addbal ID amount - Add balance
     */
    private function cmdAddBalance($chatId, $text)
    {
        $parts = explode(' ', $text);
        $userId = $parts[1] ?? null;
        $amount = floatval($parts[2] ?? 0);

        if (!$userId || $amount == 0) {
            return $this->sendMessage($chatId, "❌ Использование: /addbal ID сумма\nПример: /addbal 1 100");
        }

        // Amount validation
        if ($amount < -1000000 || $amount > 1000000) {
            return $this->sendMessage($chatId, "❌ Сумма должна быть от -1000000 до 1000000");
        }

        $user = User::find($userId);
        if (!$user) {
            return $this->sendMessage($chatId, "❌ Пользователь ID {$userId} не найден");
        }

        try {
            $oldBalance = $user->balance;
            $newBalance = $user->balance + $amount;
            
            // Don't allow negative balance
            if ($newBalance < 0) {
                $newBalance = 0;
            }
            
            // Use transaction for balance change
            DB::transaction(function () use ($user, $newBalance) {
                $user->balance = $newBalance;
                $user->save();
            });
            
            Log::info("Admin balance change", [
                'user_id' => $user->id,
                'old_balance' => $oldBalance,
                'new_balance' => $newBalance,
                'amount' => $amount
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to update balance", ['user_id' => $userId, 'error' => $e->getMessage()]);
            return $this->sendMessage($chatId, "⚠️ Ошибка при изменении баланса");
        }

        $action = $amount > 0 ? "Добавлено" : "Снято";
        $message = "✅ *Баланс изменен*\n\n";
        $message .= "👤 {$user->username} (ID: {$user->id})\n";
        $message .= "💰 Было: {$oldBalance} ₽\n";
        $message .= "💵 {$action}: " . abs($amount) . " ₽\n";
        $message .= "💰 Стало: {$user->balance} ₽";

        // Notify user if has TG (only when adding)
        if ($amount > 0 && $user->tg_id && $user->tg_id != '0') {
            self::notify($user->tg_id, "🎁 Вам начислено {$amount} ₽ от администрации!");
        }

        return $this->sendMessage($chatId, $message, 'Markdown');
    }

    /**
     * /setbal ID amount - Set balance
     */
    private function cmdSetBalance($chatId, $text)
    {
        $parts = explode(' ', $text);
        $userId = $parts[1] ?? null;
        $amount = floatval($parts[2] ?? 0);

        if (!$userId) {
            return $this->sendMessage($chatId, "❌ Использование: /setbal ID сумма\nПример: /setbal 1 500");
        }

        // Amount validation
        if ($amount < 0) {
            return $this->sendMessage($chatId, "❌ Сумма не может быть отрицательной");
        }
        
        if ($amount > 10000000) {
            return $this->sendMessage($chatId, "❌ Максимальная сумма: 10000000");
        }

        $user = User::find($userId);
        if (!$user) {
            return $this->sendMessage($chatId, "❌ Пользователь ID {$userId} не найден");
        }

        try {
            $oldBalance = $user->balance;
            
            // Use transaction for balance change
            DB::transaction(function () use ($user, $amount) {
                $user->balance = $amount;
                $user->save();
            });
            
            Log::info("Admin set balance", [
                'user_id' => $user->id,
                'old_balance' => $oldBalance,
                'new_balance' => $amount
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to set balance", ['user_id' => $userId, 'error' => $e->getMessage()]);
            return $this->sendMessage($chatId, "⚠️ Ошибка при установке баланса");
        }

        $message = "✅ *Баланс установлен*\n\n";
        $message .= "👤 {$user->username} (ID: {$user->id})\n";
        $message .= "💰 Было: {$oldBalance} ₽\n";
        $message .= "💰 Стало: {$user->balance} ₽";

        return $this->sendMessage($chatId, $message, 'Markdown');
    }

    /**
     * /allusers - Топ пользователей
     */
    private function cmdAllUsers($chatId)
    {
        $users = User::orderBy('balance', 'desc')->limit(20)->get();

        $message = "👥 *Топ 20 пользователей по балансу*\n\n";
        
        foreach ($users as $i => $user) {
            $num = $i + 1;
            $message .= "{$num}. {$user->username} - {$user->balance} ₽ (ID: {$user->id})\n";
        }

        return $this->sendMessage($chatId, $message, 'Markdown');
    }

    /**
     * /todaystats - Статистика за сегодня
     */
    private function cmdTodayStats($chatId)
    {
        $todayUsers = User::whereDate('created_at', today())->count();
        $todayDeposits = Payment::whereDate('created_at', today())->where('status', 1)->sum('sum');
        $todayDepositsCount = Payment::whereDate('created_at', today())->where('status', 1)->count();
        
        // Статистика по играм за сегодня (если есть таблица)
        $message = "📊 *Статистика за сегодня*\n\n";
        $message .= "🆕 Новых юзеров: {$todayUsers}\n";
        $message .= "💳 Депозитов: {$todayDepositsCount} шт\n";
        $message .= "💰 Сумма депозитов: {$todayDeposits} ₽\n\n";
        
        $message .= "📅 Дата: " . date('d.m.Y');

        return $this->sendMessage($chatId, $message, 'Markdown');
    }

    /**
     * /broadcast текст - Рассылка всем пользователям с TG
     */
    private function cmdBroadcast($chatId, $text)
    {
        $message = trim(str_replace('/broadcast ', '', $text));
        
        if (empty($message)) {
            return $this->sendMessage($chatId, "❌ Использование:\n`/broadcast текст` - новая рассылка\n`/broadcast continue` - продолжить прерванную", 'Markdown');
        }

        // Команда продолжения прерванной рассылки
        if ($message === 'continue') {
            return $this->continueBroadcast($chatId);
        }

        // Проверяем нет ли незавершённой рассылки
        $pending = Broadcast::where('status', 'running')->first();
        if ($pending) {
            return $this->sendMessage($chatId, "⚠️ Есть незавершённая рассылка (ID: {$pending->id})\n\nОтправлено: {$pending->sent_count}/{$pending->total_users}\n\nИспользуй `/broadcast continue` чтобы продолжить", 'Markdown');
        }

        // Получаем количество юзеров
        $totalUsers = User::where('tg_id', '!=', '0')
            ->whereNotNull('tg_id')
            ->where('tg_id', '!=', '')
            ->count();

        if ($totalUsers == 0) {
            return $this->sendMessage($chatId, "❌ Нет пользователей с привязанным Telegram");
        }

        // Создаём запись о рассылке
        $broadcast = Broadcast::create([
            'message' => $message,
            'total_users' => $totalUsers,
            'status' => 'running',
            'admin_tg_id' => $chatId
        ]);

        $this->sendMessage($chatId, "📢 Рассылка #{$broadcast->id} начата\nВсего: {$totalUsers} юзеров\n\nЕсли прервётся - используй `/broadcast continue`", 'Markdown');

        return $this->executeBroadcast($broadcast, $chatId);
    }

    /**
     * Продолжить прерванную рассылку
     */
    private function continueBroadcast($chatId)
    {
        $broadcast = Broadcast::where('status', 'running')->first();
        
        if (!$broadcast) {
            return $this->sendMessage($chatId, "❌ Нет прерванных рассылок");
        }

        $remaining = $broadcast->total_users - $broadcast->sent_count - $broadcast->failed_count;
        $this->sendMessage($chatId, "📢 Продолжаю рассылку #{$broadcast->id}\nОсталось: ~{$remaining} юзеров");

        return $this->executeBroadcast($broadcast, $chatId);
    }

    /**
     * Выполнение рассылки с сохранением прогресса
     */
    private function executeBroadcast($broadcast, $chatId)
    {
        $sent = $broadcast->sent_count;
        $failed = $broadcast->failed_count;

        User::where('tg_id', '!=', '0')
            ->whereNotNull('tg_id')
            ->where('tg_id', '!=', '')
            ->where('id', '>', $broadcast->last_user_id)
            ->select(['id', 'tg_id'])
            ->orderBy('id')
            ->chunk(50, function ($users) use ($broadcast, &$sent, &$failed) {
                foreach ($users as $user) {
                    try {
                        $result = $this->apiRequest('sendMessage', [
                            'chat_id' => $user->tg_id,
                            'text' => $broadcast->message,
                            'parse_mode' => 'Markdown'
                        ]);
                        
                        if (isset($result['ok']) && $result['ok']) {
                            $sent++;
                        } else {
                            $failed++;
                        }
                    } catch (\Exception $e) {
                        $failed++;
                    }
                    
                    // Сохраняем прогресс после каждого сообщения
                    $broadcast->update([
                        'sent_count' => $sent,
                        'failed_count' => $failed,
                        'last_user_id' => $user->id
                    ]);
                    
                    usleep(self::BROADCAST_DELAY_MS);
                }
            });

        // Рассылка завершена
        $broadcast->update(['status' => 'completed']);

        $response = "📢 *Рассылка #{$broadcast->id} завершена*\n\n";
        $response .= "✅ Отправлено: {$sent}\n";
        $response .= "❌ Ошибок: {$failed}\n";
        $response .= "📊 Всего: " . ($sent + $failed);

        return $this->sendMessage($chatId, $response, 'Markdown');
    }

    /**
     * /promo - Создать промокод через бота
     * Форматы:
     * /promo КОД сумма активаций [вагер] [тип]
     * 
     * Типы: balance (по умолчанию), deposit
     * 
     * Примеры:
     * /promo BONUS100 100 50 - 100₽ на баланс, 50 активаций
     * /promo BONUS100 100 50 3 - 100₽, 50 активаций, вагер x3
     * /promo DEP50 50 100 0 deposit - +50% к депозиту, 100 активаций
     */
    private function cmdCreatePromo($chatId, $text)
    {
        $parts = explode(' ', $text);
        $code = strtoupper($parts[1] ?? '');
        $sum = floatval($parts[2] ?? 0);
        $activations = intval($parts[3] ?? 1);
        $wager = floatval($parts[4] ?? 0);
        $type = $parts[5] ?? 'balance';

        if (empty($code)) {
            $help = "🎁 *Создание промокода*\n\n";
            $help .= "`/promo КОД сумма активаций [вагер] [тип]`\n\n";
            $help .= "*Примеры:*\n";
            $help .= "`/promo BONUS100 100 50` - 100₽ на баланс\n";
            $help .= "`/promo BONUS100 100 50 3` - с вагером x3\n";
            $help .= "`/promo DEP50 50 100 5 deposit` - +50% к депозиту\n\n";
            $help .= "*Типы:* balance, deposit";
            return $this->sendMessage($chatId, $help, 'Markdown');
        }

        // Валидация
        if ($sum <= 0) {
            return $this->sendMessage($chatId, "❌ Сумма должна быть больше 0");
        }
        
        if ($sum > 100000) {
            return $this->sendMessage($chatId, "❌ Максимальная сумма: 100000");
        }

        if ($activations <= 0) $activations = 1;
        if ($activations > 10000) {
            return $this->sendMessage($chatId, "❌ Максимум активаций: 10000");
        }

        if ($wager < 0) $wager = 0;
        if ($wager > 100) {
            return $this->sendMessage($chatId, "❌ Максимальный вагер: x100");
        }

        // Валидация типа
        if (!in_array($type, ['balance', 'deposit'])) {
            $type = 'balance';
        }

        // Проверяем существует ли уже такой промокод
        $existing = Promocode::where('name', $code)->first();
        if ($existing) {
            return $this->sendMessage($chatId, "❌ Промокод `{$code}` уже существует!", 'Markdown');
        }

        // Создаем промокод
        $promo = Promocode::create([
            'name' => $code,
            'sum' => $sum,
            'activation' => $activations,
            'wager' => $wager,
            'type' => $type
        ]);

        $message = "✅ *Промокод создан!*\n\n";
        $message .= "🎁 Код: `{$code}`\n";
        
        if ($type === 'deposit') {
            $message .= "📈 Тип: +{$sum}% к депозиту\n";
        } else {
            $message .= "💰 Сумма: {$sum} ₽\n";
        }
        
        $message .= "🔢 Активаций: {$activations}\n";
        
        if ($wager > 0) {
            $message .= "🎯 Вагер: x{$wager}\n";
        }
        
        $message .= "\nРазослать промокод всем пользователям?";

        // Отправляем с inline кнопками
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ Да, разослать', 'callback_data' => "broadcast_promo_{$code}"],
                    ['text' => '❌ Нет', 'callback_data' => 'cancel_broadcast']
                ]
            ]
        ];

        $this->apiRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard)
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * Рассылка промокода всем пользователям
     */
    private function broadcastPromo($chatId, $promoCode)
    {
        $promo = Promocode::where('name', $promoCode)->first();
        
        if (!$promo) {
            return $this->sendMessage($chatId, "❌ Промокод не найден");
        }

        // Получаем всех юзеров с привязанным TG
        $users = User::where('tg_id', '!=', '0')
            ->whereNotNull('tg_id')
            ->where('tg_id', '!=', '')
            ->get();

        $sent = 0;
        $failed = 0;

        // Формируем сообщение
        $promoMessage = "🎁 <b>ПРОМОКОД ОТ GOLDEN1X!</b>\n\n";
        
        if ($promo->type === 'deposit') {
            $promoMessage .= "💵 Получи <b>+{$promo->sum}%</b> к следующему депозиту!\n\n";
        } else {
            $promoMessage .= "💵 Получи <b>{$promo->sum} ₽</b> на баланс!\n\n";
        }
        
        $promoMessage .= "🔑 Твой промокод: <code>{$promoCode}</code>\n\n";
        
        if ($promo->wager > 0) {
            $promoMessage .= "🎯 Вагер: x{$promo->wager}\n";
        }
        
        if ($promo->type === 'deposit') {
            $promoMessage .= "⚡ Введи промокод при пополнении!\n";
        } else {
            $promoMessage .= "⚡ Активируй на сайте в разделе «Бонусы»\n";
        }
        
        $promoMessage .= "⏱ Количество активаций ограничено!\n\n";
        $promoMessage .= "🎰 <a href=\"https://" . env('APP_DOMAIN', 'golden1x.ru') . "/bonus\">Активировать на сайте</a>";

        // Путь к баннеру промокода
        $photoPath = public_path('assets/image/promo_banner.png');
        
        foreach ($users as $user) {
            try {
                // Отправляем фото с подписью
                if (file_exists($photoPath)) {
                    $result = $this->sendPhoto($user->tg_id, $photoPath, $promoMessage);
                } else {
                    $result = $this->apiRequest('sendMessage', [
                        'chat_id' => $user->tg_id,
                        'text' => $promoMessage,
                        'parse_mode' => 'HTML',
                        'disable_web_page_preview' => true
                    ]);
                }
                
                if (isset($result['ok']) && $result['ok']) {
                    $sent++;
                } else {
                    $failed++;
                    \Log::warning('Promo broadcast failed', ['user_id' => $user->id, 'tg_id' => $user->tg_id, 'result' => $result]);
                }
            } catch (\Exception $e) {
                $failed++;
                \Log::error('Promo broadcast exception', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            }
            
            usleep(50000); // 50ms задержка
        }

        $response = "📢 *Рассылка промокода завершена*\n\n";
        $response .= "🎁 Код: `{$promoCode}`\n";
        $response .= "✅ Отправлено: {$sent}\n";
        $response .= "❌ Ошибок: {$failed}";

        return $this->sendMessage($chatId, $response, 'Markdown');
    }

    /**
     * /ban ID - Забанить пользователя
     */
    private function cmdBan($chatId, $text)
    {
        $parts = explode(' ', $text);
        $userId = $parts[1] ?? null;

        if (!$userId) {
            return $this->sendMessage($chatId, "❌ Использование: /ban ID");
        }

        $user = User::find($userId);
        if (!$user) {
            return $this->sendMessage($chatId, "❌ Пользователь ID {$userId} не найден");
        }

        $user->ban = 1;
        $user->save();

        // Уведомляем юзера
        if ($user->tg_id && $user->tg_id != '0') {
            self::notify($user->tg_id, "🚫 Ваш аккаунт заблокирован администрацией.");
        }

        return $this->sendMessage($chatId, "🚫 Пользователь {$user->username} (ID: {$user->id}) забанен");
    }

    /**
     * /unban ID - Разбанить пользователя
     */
    private function cmdUnban($chatId, $text)
    {
        $parts = explode(' ', $text);
        $userId = $parts[1] ?? null;

        if (!$userId) {
            return $this->sendMessage($chatId, "❌ Использование: /unban ID");
        }

        $user = User::find($userId);
        if (!$user) {
            return $this->sendMessage($chatId, "❌ Пользователь ID {$userId} не найден");
        }

        $user->ban = 0;
        $user->save();

        // Уведомляем юзера
        if ($user->tg_id && $user->tg_id != '0') {
            self::notify($user->tg_id, "✅ Ваш аккаунт разблокирован!");
        }

        return $this->sendMessage($chatId, "✅ Пользователь {$user->username} (ID: {$user->id}) разбанен");
    }

    /**
     * /find ник - Поиск пользователя
     */
    private function cmdFind($chatId, $text)
    {
        $query = trim(str_replace('/find ', '', $text));
        
        if (empty($query)) {
            return $this->sendMessage($chatId, "❌ Использование: /find ник");
        }

        // Санитизация - только буквы, цифры, подчеркивание, дефис
        $safeQuery = preg_replace('/[^a-zA-Z0-9а-яА-ЯёЁ_-]/u', '', $query);
        
        if (strlen($safeQuery) < 2) {
            return $this->sendMessage($chatId, "❌ Минимум 2 символа для поиска");
        }

        $numericQuery = is_numeric($safeQuery) ? intval($safeQuery) : 0;

        $users = User::where(function($q) use ($safeQuery, $numericQuery) {
            $q->where('username', 'LIKE', "%{$safeQuery}%");
            if ($numericQuery > 0) {
                $q->orWhere('id', $numericQuery)
                  ->orWhere('vk_id', $numericQuery)
                  ->orWhere('tg_id', $numericQuery);
            }
        })->limit(10)->get();

        if ($users->isEmpty()) {
            return $this->sendMessage($chatId, "❌ Пользователи не найдены");
        }

        $message = "🔍 *Результаты поиска:*\n\n";
        
        foreach ($users as $user) {
            $message .= "ID: `{$user->id}` | {$user->username} | {$user->balance} ₽\n";
        }
        
        $message .= "\n💡 Для подробной инфо: /userinfo ID";

        return $this->sendMessage($chatId, $message, 'Markdown');
    }
}
