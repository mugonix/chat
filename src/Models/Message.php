<?php

namespace Musonza\Chat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Musonza\Chat\BaseModel;
use Musonza\Chat\Chat;
use Musonza\Chat\ConfigurationManager;
use Musonza\Chat\Eventing\AllParticipantsDeletedMessage;
use Musonza\Chat\Eventing\EventGenerator;
use Musonza\Chat\Eventing\MessageWasSent;

class Message extends BaseModel
{
    use EventGenerator;

    protected $fillable = [
        'body',
        'participation_id',
        'type',
        'data',
    ];

    protected $table = ConfigurationManager::MESSAGES_TABLE;
    /**
     * All of the relationships to be touched.
     *
     * @var array
     */
    protected $touches = ['conversation'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'flagged' => 'boolean',
        'data'    => 'array',
    ];

    protected $appends = ['sender'];

    public function participation()
    {
        return $this->belongsTo(Participation::class, 'participation_id');
    }

    public function getSenderAttribute()
    {
        $participantModel = $this->participation->messageable;

        if (!isset($participantModel)) {
            return null;
        }

        if (method_exists($participantModel, 'getParticipantDetails')) {
            return $participantModel->getParticipantDetails();
        }

        $fields = Chat::senderFieldsWhitelist();

        return $fields ? $this->participation->messageable->only($fields) : $this->participation->messageable;
    }

    public function unreadCount(Model $participant)
    {
        return MessageNotification::where('messageable_id', $participant->getKey())
            ->where('is_seen', 0)
            ->where('messageable_type', $participant->getMorphClass())
            ->count();
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    /**
     * @param Model $participant
     * @param $options
     *
     * @return mixed
     */
    public function getConversationEntity(Model $participant, $options)
    {
        /** @var Builder $paginator */
        $paginator = $this->conversation()
            ->join($this->tablePrefix.'participation',$this->tablePrefix.'participation.conversation_id', '=', $this->tablePrefix.'conversations.id')
            ->where(function ($query) use ($participant) {
                $query->where($this->tablePrefix.'participation.messageable_id', $participant->getKey())
                    ->where($this->tablePrefix.'participation.messageable_type', $participant->getMorphClass());
            })
            ->with([
                'last_message' => function ($query) use ($participant) {
                    $query->join($this->tablePrefix.'message_notifications', $this->tablePrefix.'message_notifications.message_id', '=', $this->tablePrefix.'messages.id')
                        ->select($this->tablePrefix.'message_notifications.*', $this->tablePrefix.'messages.*')
                        ->where($this->tablePrefix.'message_notifications.messageable_id', $participant->getKey())
                        ->where($this->tablePrefix.'message_notifications.messageable_type', $participant->getMorphClass())
                        ->whereNull($this->tablePrefix.'message_notifications.deleted_at');
                },
            ]);

        if (isset($options['filters']['include_unread_count']) && $options['filters']['include_unread_count']) {
            $paginator = $paginator->with([
                'unread_count' => function($query) use ($participant) {
                    $query->where(function ($q) use ($participant) {
                        $q->where('messageable_id', $participant->getKey())
                            ->where('messageable_type', $participant->getMorphClass());
                    });
                }
            ]);
        }

        if (isset($options['filters']['private'])) {
            $paginator = $paginator->where($this->tablePrefix.'conversations.private', (bool) $options['filters']['private']);
        }

        if (isset($options['filters']['direct_message'])) {
            $direct_message = (bool) $options['filters']['direct_message'];

            if($direct_message) {
                $paginator = $paginator
                    ->with(['participant' => function ($query) use ($participant) {
                        $query->whereNot(function ($q) use ($participant) {
                            $q->where('messageable_id', $participant->getKey())
                                ->where('messageable_type', $participant->getMorphClass());
                        });
                    }, 'participant.messageable']);
            }else {
                $paginator = $paginator->with(['participants.messageable']);
            }

            $paginator = $paginator->where($this->tablePrefix.'conversations.direct_message', $direct_message);
        }

        return $paginator
            ->orderBy($this->tablePrefix.'conversations.updated_at', 'DESC')
            ->orderBy($this->tablePrefix.'conversations.id', 'DESC')
            ->distinct($this->tablePrefix.'conversations.id')
            ->first([$this->tablePrefix.'participation.*', $this->tablePrefix.'conversations.*']);
    }

    /**
     * Adds a message to a conversation.
     *
     * @param Conversation  $conversation
     * @param string        $body
     * @param Participation $participant
     * @param string        $type
     *
     * @return Model
     */
    public function send(Conversation $conversation, string $body, Participation $participant, string $type = 'text', array $data = []): Model
    {
        $message = $conversation->messages()->create([
            'body'             => $body,
            'participation_id' => $participant->getKey(),
            'type'             => $type,
            'data'             => $data,
        ]);

        if (Chat::broadcasts()) {
            broadcast(new MessageWasSent($message))->toOthers();
        }

        $this->createNotifications($message);

        return $message;
    }

    /**
     * Creates an entry in the message_notification table for each participant
     * This will be used to determine if a message is read or deleted.
     *
     * @param Message $message
     */
    protected function createNotifications($message)
    {
        MessageNotification::make($message, $message->conversation);
    }

    /**
     * Deletes a message for the participant.
     *
     * @param Model $participant
     *
     * @return void
     */
    public function trash(Model $participant): void
    {
        MessageNotification::where('messageable_id', $participant->getKey())
            ->where('messageable_type', $participant->getMorphClass())
            ->where('message_id', $this->getKey())
            ->delete();

        if ($this->unDeletedCount() === 0) {
            event(new AllParticipantsDeletedMessage($this));
        }
    }

    public function unDeletedCount()
    {
        return MessageNotification::where('message_id', $this->getKey())
            ->count();
    }

    /**
     * Return user notification for specific message.
     *
     * @param Model $participant
     *
     * @return MessageNotification|null
     */
    public function getNotification(Model $participant): ?MessageNotification
    {
        return MessageNotification::where('messageable_id', $participant->getKey())
            ->where('messageable_type', $participant->getMorphClass())
            ->where('message_id', $this->id)
            ->select([
                '*',
                'updated_at as read_at',
            ])
            ->first();
    }

    /**
     * Marks message as read.
     *
     * @param $participant
     */
    public function markRead($participant): void
    {
        $this->getNotification($participant)->markAsRead();
    }

    public function flagged(Model $participant): bool
    {
        return (bool) MessageNotification::where('messageable_id', $participant->getKey())
            ->where('message_id', $this->id)
            ->where('messageable_type', $participant->getMorphClass())
            ->where('flagged', 1)
            ->first();
    }

    public function toggleFlag(Model $participant): self
    {
        MessageNotification::where('messageable_id', $participant->getKey())
            ->where('message_id', $this->id)
            ->where('messageable_type', $participant->getMorphClass())
            ->update(['flagged' => $this->flagged($participant) ? false : true]);

        return $this;
    }
}
