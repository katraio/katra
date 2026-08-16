<?php

namespace App\Enums;

enum AttentionKind: string
{
    case MessageMention = 'message-mention';
    case MessageAttentionRequest = 'message-attention-request';
    case DirectMessageContinuation = 'direct-message-continuation';
    case MeetingAction = 'meeting-action';
}
