<?php

namespace Katra\LiveKit;

use InvalidArgumentException;

final readonly class ParticipantGrant
{
    public const CAMERA = 'camera';

    public const MICROPHONE = 'microphone';

    public const SCREEN_SHARE = 'screen_share';

    public const SCREEN_SHARE_AUDIO = 'screen_share_audio';

    private const ALLOWED_SOURCES = [
        self::CAMERA,
        self::MICROPHONE,
        self::SCREEN_SHARE,
        self::SCREEN_SHARE_AUDIO,
    ];

    /**
     * @param  list<string>  $publishSources
     */
    public function __construct(
        public string $roomName,
        public string $participantIdentity,
        public array $publishSources = self::ALLOWED_SOURCES,
        public bool $canSubscribe = true,
    ) {
        if (trim($roomName) === '') {
            throw new InvalidArgumentException('A LiveKit room name is required.');
        }
        if (trim($participantIdentity) === '') {
            throw new InvalidArgumentException('A LiveKit participant identity is required.');
        }

        $unknown = array_diff($publishSources, self::ALLOWED_SOURCES);
        if ($unknown !== []) {
            throw new InvalidArgumentException('The LiveKit participant grant contains an unsupported publish source.');
        }
    }
}
