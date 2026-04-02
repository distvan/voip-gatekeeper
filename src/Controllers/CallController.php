<?php

namespace App\Controllers;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

class CallController
{
    private const XML_CONTENT_TYPE = 'application/xml';

    private string $mobileNumber;
    private string $sayVoice;
    private string $sayLanguage;

    public function __construct(string $mobileNumber, string $sayVoice = 'alice', string $sayLanguage = 'hu-HU')
    {
        $this->mobileNumber = $mobileNumber;
        $this->sayVoice = $sayVoice;
        $this->sayLanguage = $sayLanguage;
    }

    public function incomingCall(Request $_request, Response $response): Response
    {
        unset($_request);

        $sayAttributes = $this->buildSayAttributes();

        $xml = <<<XML
        <Response>
            <Say{$sayAttributes}>
                Nyilatkozom, hogy marketing és reklám célú hívásokat nem fogadok.
            </Say>
            <Gather numDigits="1" action="/gather" timeout="5">
                <Say{$sayAttributes}>
                    Tájékoztatom, hogy a hívás rögzítésre kerül.
                    Az egyes gomb megnyomásával Ön beleegyezik a hívás rögzítésébe.
                </Say>
            </Gather>
            <Redirect>/reject</Redirect>
        </Response>
        XML;
        
        $response->getBody()->write($xml);
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
        $digit = $request->getParsedBody()['Digits'] ?? '';
        
        if ($digit == '1') {
            $xml = <<<XML
                <Response>
                    <Dial callerId="{$_POST['From']}" record="record-from-answer">
                        <Number>{$this->mobileNumber}</Number>
                    </Dial>
                </Response>
            XML;
        } else {
            $xml = <<<XML
                <Response>
                    <Say>Hibás választás. A hívás bontásra kerül.</Say>
                    <Hangup/>
                </Response>
            XML;
        }
        
        $response->getBody()->write($xml);
        return $response->withHeader('Content-Type', self::XML_CONTENT_TYPE);
    }

    public function reject(Request $_request, Response $response): Response
    {
        unset($_request);

        $xml = <<<XML
            <Response>
                <Say>Nem történt megerősítés. Viszonthallásra.</Say>
                <Hangup/>
            </Response>
        XML;
        
        $response->getBody()->write($xml);
        return $response->withHeader('Content-Type', self::XML_CONTENT_TYPE);
    }
}
