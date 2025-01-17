<?php
/**
 * This file is part of the TelegramBot package.
 *
 * (c) Avtandil Kikabidze aka LONGMAN <akalongman@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Longman\TelegramBot\Commands\SystemCommands;

use Longman\TelegramBot\Commands\SystemCommand;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Entities\ServerResponse;

/**
 * Callback query command
 *
 * This command handles all callback queries sent via inline keyboard buttons.
 *
 * @see InlinekeyboardCommand.php
 */
class CallbackqueryCommand extends SystemCommand
{
    /**
     * @var string
     */
    protected $name = 'callbackquery';

    /**
     * @var string
     */
    protected $description = 'Reply to callback query';

    /**
     * @var string
     */
    protected $version = '1.1.1';

    /**
     * Command execute method
     *
     * @return \Longman\TelegramBot\Entities\ServerResponse
     * @throws \Longman\TelegramBot\Exception\TelegramException
     */
    public function execute(): ServerResponse
    {
        $callback_query    = $this->getCallbackQuery();
        $callback_query_id = $callback_query->getId();
        $callback_data     = $callback_query->getData();

        $message = $callback_query->getMessage();

        $paramsCallback = explode('||',$callback_data);

        $telegramExt = \erLhcoreClassModule::getExtensionInstance('erLhcoreClassExtensionLhctelegram');
        $tBot = $telegramExt->getBot();

        if ($paramsCallback[0] == 'go_offline' || $paramsCallback[0] == 'go_online') {

            $operator = \erLhcoreClassModelTelegramOperator::findOne(array('filter' => array('bot_id' => $tBot->id, 'confirmed' => 1, 'tuser_id' => $callback_query->getFrom()->getId())));

            if ($operator instanceof \erLhcoreClassModelTelegramOperator) {

                $userData = $operator->user;

                if ($paramsCallback[0] == 'go_offline') {
                    $userData->hide_online = 1;
                } else {
                    $userData->hide_online = 0;
                }

                \erLhcoreClassUser::getSession()->update($userData);

                \erLhcoreClassUserDep::setHideOnlineStatus($userData);

                \erLhcoreClassChat::updateActiveChats($userData->id);

                \erLhcoreClassChatEventDispatcher::getInstance()->dispatch('chat.operator_status_changed',array('user' => & $userData, 'reason' => 'user_action'));

                $data = [
                    'callback_query_id' => $callback_query_id,
                    'text'              => 'Your online status was changed to '. ($paramsCallback[0] == 'go_offline' ? 'Offline' : 'Online') .'!',
                    'show_alert'        => false,
                    'cache_time'        => 5,
                ];

                Request::answerCallbackQuery($data);

            } else {
                $data = [
                    'chat_id' => $callback_query->getFrom()->getId(),
                    'text'    => 'Operator could not be found!',
                ];

                return Request::sendMessage($data);
            }


        } else if ($paramsCallback[0] == 'replycommand') {

            $data = [
                'callback_query_id' => $callback_query_id,
                'text'              => 'Executing!',
                'show_alert'        => false,
                'cache_time'        => 5,
            ];

            Request::answerCallbackQuery($data);

            if ($this->getTelegram()->getCommandObject($paramsCallback[1])) {
                return $this->getTelegram()->executeCommand($paramsCallback[1]);
            }

        } else if ($paramsCallback[0] == 'take_over_chat') {

            $chat = \erLhcoreClassModelChat::fetch($paramsCallback[1]);

            $operator = \erLhcoreClassModelTelegramOperator::findOne(array('filter' => array('bot_id' => $tBot->id, 'confirmed' => 1, 'tuser_id' => $callback_query->getFrom()->getId())));

            if ($operator instanceof \erLhcoreClassModelTelegramOperator)
            {
                $chat->user_id = $operator->user_id;
                $chat->status_sub = \erLhcoreClassModelChat::STATUS_SUB_OWNER_CHANGED;

                $msg = new \erLhcoreClassModelmsg();
                $msg->msg = (string)$operator->user->name_support.' '.\erTranslationClassLhTranslation::getInstance()->getTranslation('chat/adminchat','took over the chat!');
                $msg->chat_id = $chat->id;
                $msg->user_id = -1;
                $msg->time = time();

                \erLhcoreClassChat::getSession()->save($msg);

                $chat->last_msg_id = $msg->id;

                $chat->support_informed = 1;
                $chat->has_unread_messages = 0;
                $chat->unread_messages_informed = 0;

                if ($chat->unanswered_chat == 1 && $chat->user_status == \erLhcoreClassModelChat::USER_STATUS_JOINED_CHAT)
                {
                    $chat->unanswered_chat = 0;
                }

                $variablesArray = $chat->chat_variables_array;

                if (!is_array($variablesArray)) {
                    $variablesArray = array();
                }

                $variablesArray['telegram_chat_op'] = $operator->id;
                $chat->chat_variables = json_encode($variablesArray);
                $chat->chat_variables_array = $variablesArray;

                $chat->saveThis();

                $operator->chat_id = $chat->id;
                $operator->saveThis();

                $data = [
                    'callback_query_id' => $callback_query_id,
                    'text'              => 'Chat was taken over!',
                    'show_alert'        => false,
                    'cache_time'        => 5,
                ];

                Request::answerCallbackQuery($data);

                $data = [
                    'chat_id' => $tBot->group_chat_id,
                    'message_thread_id' => $message->getMessageThreadId(),
                    'text'    => 'Chat was taken over accepted!',
                ];

                Request::sendMessage($data);
            }

        } else if ($paramsCallback[0] == 'accept_chat') {

            $chat = \erLhcoreClassModelChat::fetch($paramsCallback[1]);

            $operator = \erLhcoreClassModelTelegramOperator::findOne(array('filter' => array('bot_id' => $tBot->id, 'confirmed' => 1, 'tuser_id' => $callback_query->getFrom()->getId())));

            $transfer = null;

            // Perhaps it was a transfer
            if ($operator instanceof \erLhcoreClassModelTelegramOperator)
            {
                $transfer = \erLhcoreClassModelTransfer::findOne(array('filter' => array('chat_id' => $chat->id, 'transfer_to_user_id' => $operator->user_id)));
            }

            if ($transfer instanceof \erLhcoreClassModelTransfer || $chat->status == \erLhcoreClassModelChat::STATUS_PENDING_CHAT || $chat->status == \erLhcoreClassModelChat::STATUS_BOT_CHAT || ($chat->status == \erLhcoreClassModelChat::STATUS_ACTIVE_CHAT && $operator instanceof \erLhcoreClassModelTelegramOperator && $operator->user_id == $chat->user_id)) {

                $wasPending = $chat->status == \erLhcoreClassModelChat::STATUS_PENDING_CHAT;

                $chat->status = \erLhcoreClassModelChat::STATUS_ACTIVE_CHAT;
                $chat->status_sub = \erLhcoreClassModelChat::STATUS_SUB_OWNER_CHANGED;

                if ($chat->wait_time == 0) {
                    $chat->wait_time = time() - $chat->time;
                }

                if ($operator instanceof \erLhcoreClassModelTelegramOperator)
                {
                    $chat->user_id = $operator->user_id;

                    if ($wasPending == true){
                        // User status in event of chat acceptance
                        $chat->usaccept = $operator->user->hide_online;

                        $msg = new \erLhcoreClassModelmsg();
                        $msg->msg = (string)$operator->user->name_support.' '.\erTranslationClassLhTranslation::getInstance()->getTranslation('chat/adminchat','has accepted the chat!');
                        $msg->chat_id = $chat->id;
                        $msg->user_id = -1;
                        $msg->time = time();

                        \erLhcoreClassChat::getSession()->save($msg);

                        $chat->last_msg_id = $msg->id;
                    }

                    $chat->support_informed = 1;
                    $chat->has_unread_messages = 0;
                    $chat->unread_messages_informed = 0;

                    if ($chat->unanswered_chat == 1 && $chat->user_status == \erLhcoreClassModelChat::USER_STATUS_JOINED_CHAT)
                    {
                        $chat->unanswered_chat = 0;
                    }

                    $variablesArray = $chat->chat_variables_array;

                    if (!is_array($variablesArray)) {
                        $variablesArray = array();
                    }

                    $variablesArray['telegram_chat_op'] = $operator->id;
                    $chat->chat_variables = json_encode($variablesArray);
                    $chat->chat_variables_array = $variablesArray;

                    // Check does chat transfer record exists if operator opened chat directly
                    if ($chat->transfer_uid > 0) {
                        \erLhcoreClassTransfer::handleTransferredChatOpen($chat, $operator->user_id);
                    }

                    $chat->saveThis();

                    $operator->chat_id = $chat->id;
                    $operator->saveThis();

                    $data = [
                        'callback_query_id' => $callback_query_id,
                        'text'              => 'Chat was accepted!',
                        'show_alert'        => false,
                        'cache_time'        => 5,
                    ];

                    Request::answerCallbackQuery($data);

                    $data = [
                        'chat_id' => $tBot->group_chat_id,
                        'message_thread_id' => $message->getMessageThreadId(),
                        'text'    => 'Chat was accepted!',
                    ];

                    Request::sendMessage($data);

                } else {
                    $data = [
                        'callback_query_id' => $callback_query_id,
                        'text'              => 'Operator could not be found!',
                        'show_alert'        => false,
                        'cache_time'        => 5,
                    ];

                    Request::answerCallbackQuery($data);

                    $data = [
                        'chat_id' => $callback_query->getFrom()->getId(),
                        'text'    => 'Operator could not be found. Please check back office!',
                    ];

                    Request::sendMessage($data);
                }

            } else {

                $data = [
                    'callback_query_id' => $callback_query_id,
                    'text'              => 'Chat was already accepted.',
                    'show_alert'        => false,
                    'cache_time'        => 5,
                ];

                Request::answerCallbackQuery($data);

                $inline_keyboard = new InlineKeyboard([
                    ['text' => 'Take Over', 'callback_data' => 'take_over_chat||' .$chat->id]
                ]);

                $data = [
                    'chat_id' => $callback_query->getFrom()->getId(),
                    'text'    => "Chat was already accepted by. " . (string)$chat->user,
                    'reply_markup' => $inline_keyboard,
                ];

                Request::sendMessage($data);
            }

        } else {
            $data = [
                'callback_query_id' => $callback_query_id,
                'text'              => 'Chat with - ' . $callback_data,
                'show_alert'        => $callback_data === 'thumb up',
                'cache_time'        => 5,
            ];

            Request::answerCallbackQuery($data);

            $data = [
                'chat_id' => $callback_query->getFrom()->getId(),
                'text'    => 'Chat accepted',
            ];

            return Request::sendMessage($data);
        }

        return Request::emptyResponse();
    }
}
