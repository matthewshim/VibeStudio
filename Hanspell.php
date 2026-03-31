<?php

class Hanspell
{
    private $agent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36';
    private $base_url = 'https://ts-proxy.naver.com/ocontent/util/SpellerProxy';
    private $passportKey = null;

    /**
     * 맞춤법 검사 실행
     * @param string $text 검사할 텍스트
     * @return array 결과 데이터
     */
    public function check($text)
    {
        if (empty(trim($text))) {
            return [
                'message' => [
                    'result' => [
                        'html' => '',
                        'origin_html' => '',
                        'errata_count' => 0
                    ]
                ]
            ];
        }

        // 유효한 passportKey 획득 시도
        if (!$this->passportKey) {
            $this->passportKey = $this->getPassportKey();
        }

        // 500자 단위로 텍스트 분할 (청크 처리)
        $chunks = $this->splitText($text, 500);
        $combinedResult = [
            'html' => '',
            'origin_html' => '',
            'errata_count' => 0
        ];

        foreach ($chunks as $chunk) {
            if (empty(trim($chunk)))
                continue;

            $response = $this->fetchNaver($chunk);
            $parsed = $this->parseResponse($response);

            if ($parsed && isset($parsed['message']['result'])) {
                $res = $parsed['message']['result'];

                // 결합 시 공백 처리
                $separator = empty($combinedResult['html']) ? '' : ' ';
                $combinedResult['html'] .= $separator . $res['html'];
                $combinedResult['origin_html'] .= $separator . $res['origin_html'];
                $combinedResult['errata_count'] += ($res['errata_count'] ?? 0);
            }
        }

        return [
            'message' => [
                'result' => [
                    'html' => $combinedResult['html'],
                    'origin_html' => $combinedResult['origin_html'],
                    'errata_count' => $combinedResult['errata_count'],
                    'err_cnt' => $combinedResult['errata_count'] // index.html 호환성 위해 추가
                ]
            ]
        ];
    }

    /**
     * 네이버 맞춤법 검사기 페이지에서 passportKey 동적 추출
     */
    private function getPassportKey()
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://search.naver.com/search.naver?query=%EB%A7%9E%EC%B6%A4%EB%B2%95%EA%B2%80%EC%82%AC%EA%B8%B0");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->agent);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        $html = curl_exec($ch);
        curl_close($ch);

        if ($html && preg_match('/passportKey=([a-f0-9]+)/i', $html, $matches)) {
            return $matches[1];
        }

        // 대체 패턴 시도 (JS 변수에 할당된 경우)
        if ($html && preg_match('/passportKey\s*[:=]\s*["\']([a-f0-9]+)["\']/i', $html, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * 네이버 서버에 요청
     */
    private function fetchNaver($text)
    {
        $params = [
            'q' => $text,
            'color_blindness' => 0,
            'where' => 'nexearch',
            'passportKey' => $this->passportKey
        ];

        $ch = curl_init();
        $url = $this->base_url . '?' . http_build_query($params);

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->agent);
        curl_setopt($ch, CURLOPT_REFERER, 'https://search.naver.com/');
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        $response = curl_exec($ch);
        curl_close($ch);

        return $response;
    }

    /**
     * JSONP 응답 파싱
     */
    private function parseResponse($response)
    {
        if (!$response)
            return null;

        $response = trim($response);
        $start = strpos($response, '{');
        $end = strrpos($response, '}');

        if ($start !== false && $end !== false) {
            $json_str = substr($response, $start, $end - $start + 1);
            return json_decode($json_str, true);
        }

        return null;
    }

    /**
     * 텍스트를 지정된 길이에 맞춰 문장 단위로 분할
     */
    private function splitText($text, $limit)
    {
        if (mb_strlen($text, 'UTF-8') <= $limit) {
            return [$text];
        }

        $chunks = [];
        $currentChunk = '';

        // 문장 종결자 기준으로 분할 시도 (. ! ? \n)
        $sentences = preg_split('/(?<=[.!?\n])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        foreach ($sentences as $sentence) {
            if (mb_strlen($currentChunk . $sentence, 'UTF-8') <= $limit) {
                $currentChunk .= $sentence . ' ';
            }
            else {
                if (!empty($currentChunk)) {
                    $chunks[] = trim($currentChunk);
                }

                // 만약 단일 문장이 제한보다 길면 강제로 자름
                if (mb_strlen($sentence, 'UTF-8') > $limit) {
                    $remain = $sentence;
                    while (mb_strlen($remain, 'UTF-8') > $limit) {
                        $chunks[] = mb_substr($remain, 0, $limit, 'UTF-8');
                        $remain = mb_substr($remain, $limit, null, 'UTF-8');
                    }
                    $currentChunk = $remain . ' ';
                }
                else {
                    $currentChunk = $sentence . ' ';
                }
            }
        }

        if (!empty(trim($currentChunk))) {
            $chunks[] = trim($currentChunk);
        }

        return $chunks;
    }
}
