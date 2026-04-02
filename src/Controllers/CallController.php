<?php

namespace App\Controllers;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

class CallController
{
    private const XML_CONTENT_TYPE = 'application/xml';
    private const VOICEMAIL_MAX_LENGTH_SECONDS = 120;

    private string $mobileNumber;
    private string $sayVoice;
    private string $sayLanguage;
    /** @var array<string, bool> */
    private array $whitelistedCallers;

    public function __construct(
        string $mobileNumber,
        string $sayVoice = 'alice',
        string $sayLanguage = 'hu-HU',
        array $whitelistedCallers = []
    )
    {
        $this->mobileNumber = $mobileNumber;
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
        return <<<XML
            <Response>
                <Dial callerId="{$callerNumber}" record="record-from-answer">
                    <Number>{$this->mobileNumber}</Number>
                </Dial>
            </Response>
        XML;
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
        $digit = $request->getParsedBody()['Digits'] ?? '';
        $sayAttributes = $this->buildSayAttributes();

        if ($digit === '1') {
            $voicemailMaxLengthSeconds = (string) self::VOICEMAIL_MAX_LENGTH_SECONDS;

            $xml = <<<XML
                <Response>
                    <Say{$sayAttributes}>Hagyjon hangüzenetet a sípszó után. A rögzítés befejezéséhez nyomja meg a kettőskeresztet.</Say>
                    <Record action="/recording-complete" method="POST" maxLength="{$voicemailMaxLengthSeconds}" playBeep="true" finishOnKey="#" timeout="5" />
                </Response>
            XML;
        } else {
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
