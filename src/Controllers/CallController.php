<?php

namespace App\Controllers;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

class CallController
{
    private const XML_CONTENT_TYPE = 'application/xml';
    private const VOICEMAIL_MAX_LENGTH_SECONDS = 120;
    private const DESTINATION_TYPE_E164 = 'e164';
    private const DESTINATION_TYPE_SIP = 'sip';
    private const DIAL_FALLBACK_STATUS_BUSY = 'busy';
    private const DIAL_FALLBACK_STATUS_CANCELED = 'canceled';
    private const DIAL_FALLBACK_STATUS_FAILED = 'failed';
    private const DIAL_FALLBACK_STATUS_NO_ANSWER = 'no-answer';

    private string $forwardDestinationType;
    private string $forwardDestination;
    private bool $enableSipFallbackToVoicemail;
    private ?int $sipTimeoutSeconds;
    private string $sayVoice;
    private string $sayLanguage;
    /** @var array<string, bool> */
    private array $whitelistedCallers;

    public function __construct(
        string $forwardDestinationType,
        string $forwardDestination,
        bool $enableSipFallbackToVoicemail = false,
        ?int $sipTimeoutSeconds = null,
        string $sayVoice = 'alice',
        string $sayLanguage = 'hu-HU',
        array $whitelistedCallers = []
    )
    {
        $this->forwardDestinationType = $forwardDestinationType;
        $this->forwardDestination = $forwardDestination;
        $this->enableSipFallbackToVoicemail = $enableSipFallbackToVoicemail;
        $this->sipTimeoutSeconds = $sipTimeoutSeconds;
        $this->sayVoice = $sayVoice;
        $this->sayLanguage = $sayLanguage;
        $this->whitelistedCallers = $whitelistedCallers;
    }

    public function incomingCall(Request $request, Response $response): Response
    {
        $callerNumber = $this->getCallerNumber($request);

        if ($this->isCallerWhitelisted($callerNumber)) {
            $response->getBody()->write($this->buildDirectDialResponse($callerNumber));

            return $response->withHeader('Content-Type', self::XML_CONTENT_TYPE);
        }

        $sayAttributes = $this->buildSayAttributes();

        $xml = <<<XML
        <Response>
            <Say{$sayAttributes}>
                Nyilatkozom, hogy marketing és reklám célú hívásokat nem fogadok.
            </Say>
            <Gather numDigits="1" action="/gather" timeout="5">
                <Say{$sayAttributes}>
                    Az egyes gomb megnyomásával hangüzenetet hagyhat a sípszó után.
                </Say>
            </Gather>
            <Redirect>/reject</Redirect>
        </Response>
        XML;
        
        $response->getBody()->write($xml);
        return $response->withHeader('Content-Type', self::XML_CONTENT_TYPE);
    }

    private function getCallerNumber(Request $request): string
    {
        $parsedBody = $request->getParsedBody();

        if (!is_array($parsedBody)) {
            return '';
        }

        $callerNumber = $parsedBody['From'] ?? '';

        return is_string($callerNumber) ? trim($callerNumber) : '';
    }

    private function isCallerWhitelisted(string $callerNumber): bool
    {
        return $callerNumber !== '' && isset($this->whitelistedCallers[$callerNumber]);
    }

    private function buildDirectDialResponse(string $callerNumber): string
    {
        $escapedDestination = htmlspecialchars($this->forwardDestination, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $escapedCallerNumber = htmlspecialchars($callerNumber, ENT_QUOTES | ENT_XML1, 'UTF-8');

        if ($this->forwardDestinationType === self::DESTINATION_TYPE_SIP) {
            $dialFallbackAttributes = $this->enableSipFallbackToVoicemail ? ' action="/dial-fallback" method="POST"' : '';
            $dialTimeoutAttribute = $this->sipTimeoutSeconds !== null ? ' timeout="' . (string) $this->sipTimeoutSeconds . '"' : '';

            return <<<XML
            <Response>
                <Dial{$dialFallbackAttributes}{$dialTimeoutAttribute} answerOnBridge="true" callerId="{$escapedCallerNumber}" record="record-from-answer">
                    <Sip>{$escapedDestination}</Sip>
                </Dial>
            </Response>
            XML;
        }

        if ($this->forwardDestinationType !== self::DESTINATION_TYPE_E164) {
            throw new \LogicException('Unsupported forward destination type.');
        }

        return <<<XML
            <Response>
                <Dial callerId="{$escapedCallerNumber}" record="record-from-answer">
                    <Number>{$escapedDestination}</Number>
                </Dial>
            </Response>
        XML;
    }

    public function dialFallback(Request $request, Response $response): Response
    {
        $dialCallStatus = $this->getStringBodyValue($request, 'DialCallStatus');

        if ($this->shouldFallbackToVoicemail($dialCallStatus)) {
            $response->getBody()->write($this->buildVoicemailRecordingResponse());

            return $response->withHeader('Content-Type', self::XML_CONTENT_TYPE);
        }

        $response->getBody()->write('<Response><Hangup/></Response>');

        return $response->withHeader('Content-Type', self::XML_CONTENT_TYPE);
    }

    private function buildSayAttributes(): string
    {
        $attributes = ' voice="' . htmlspecialchars($this->sayVoice, ENT_QUOTES | ENT_XML1, 'UTF-8') . '"';

        if ($this->sayVoice === 'alice') {
            $attributes .= ' language="' . htmlspecialchars($this->sayLanguage, ENT_QUOTES | ENT_XML1, 'UTF-8') . '"';
        }

        return $attributes;
    }

    public function gather(Request $request, Response $response): Response
    {
        $digit = $this->getStringBodyValue($request, 'Digits');

        if ($digit === '1') {
            $xml = $this->buildVoicemailRecordingResponse();
        } else {
            $sayAttributes = $this->buildSayAttributes();

            $xml = <<<XML
                <Response>
                    <Say{$sayAttributes}>Hibás választás. A hívás bontásra kerül.</Say>
                    <Hangup/>
                </Response>
            XML;
        }

        $response->getBody()->write($xml);
        return $response->withHeader('Content-Type', self::XML_CONTENT_TYPE);
    }

    private function buildVoicemailRecordingResponse(): string
    {
        $sayAttributes = $this->buildSayAttributes();
        $voicemailMaxLengthSeconds = (string) self::VOICEMAIL_MAX_LENGTH_SECONDS;

        return <<<XML
            <Response>
                <Say{$sayAttributes}>Hagyjon hangüzenetet a sípszó után. A rögzítés befejezéséhez nyomja meg a kettőskeresztet.</Say>
                <Record action="/recording-complete" method="POST" maxLength="{$voicemailMaxLengthSeconds}" playBeep="true" finishOnKey="#" timeout="5" />
            </Response>
        XML;
    }

    private function shouldFallbackToVoicemail(string $dialCallStatus): bool
    {
        if (!$this->enableSipFallbackToVoicemail) {
            return false;
        }

        return in_array(
            $dialCallStatus,
            [
                self::DIAL_FALLBACK_STATUS_BUSY,
                self::DIAL_FALLBACK_STATUS_CANCELED,
                self::DIAL_FALLBACK_STATUS_FAILED,
                self::DIAL_FALLBACK_STATUS_NO_ANSWER,
            ],
            true
        );
    }

    private function getStringBodyValue(Request $request, string $key): string
    {
        $parsedBody = $request->getParsedBody();

        if (!is_array($parsedBody)) {
            return '';
        }

        $value = $parsedBody[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    public function recordingComplete(Request $_request, Response $response): Response
    {
        unset($_request);
        $sayAttributes = $this->buildSayAttributes();

        $xml = <<<XML
            <Response>
                <Say{$sayAttributes}>Köszönöm az üzenetet. Viszonthallásra.</Say>
                <Hangup/>
            </Response>
        XML;

        $response->getBody()->write($xml);
        return $response->withHeader('Content-Type', self::XML_CONTENT_TYPE);
    }

    public function reject(Request $_request, Response $response): Response
    {
        unset($_request);

        $sayAttributes = $this->buildSayAttributes();

        $xml = <<<XML
            <Response>
                <Say{$sayAttributes}>Nem történt megerősítés. Viszonthallásra.</Say>
                <Hangup/>
            </Response>
        XML;
        
        $response->getBody()->write($xml);
        return $response->withHeader('Content-Type', self::XML_CONTENT_TYPE);
    }
}
