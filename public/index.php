<?php
use Slim\Factory\AppFactory;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

require __DIR__ . '/../vendor/autoload.php';

$app = AppFactory::create();

$app->get('/', function (ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface {
    print("Hello World!");
    return $response;
});

// SSRF vulnerable endpoint
// /ssrf?url=http://169.254.169.254/latest/meta-data/iam/security-credentials/EC2Instance
// ローカルで自分自身のファイルを読みだそうとする(url=http://localhost:8888/)と、サーバーが固まって動かなくなる
// が、http://localhost:8888/ssrf?url=file:///etc/passwdは刺さる
// gopher://localhost:8888/_GET%20/%20HTTP/1.1%0d%0aは刺さらなかった → curl_exec()はgopherプロトコルに対応してなさそう
$app->map(["GET", "POST"], '/ssrf', function (ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface {
    requestURLwithCurl($request);
    return $response;
});

// file_get_contents()を使ったurlアクセスver
// http://localhost:8888/ssrf2?url=file:///etc/passwd は刺さる
$app->get('/ssrf2', function (ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface {
    $purifier = new HTMLPurifier();

    $url = $request->getQueryParams('url');  // $urlは配列
    if (empty($url)) {
        $url = array('url'=>'http://example.com');
    }
    var_dump($url);

    $html = file_get_contents($url['url']);
    echo $purifier->purify($html);

    return $response;
});



function requestURLwithCurl(ServerRequestInterface $request) {
    $purifier = new HTMLPurifier();
    $ch = curl_init();

    if ($request->getMethod() === 'GET') {
        $url = $request->getQueryParams('url');  // $urlは配列
        if (empty($url)) {
            $url = array('url'=>'http://example.com');
        }
        $url = $url['url'];
    } else { // POSTの場合
        $url = $request->getParsedBody()['url'];
        if (is_null($url)) {
            $url = 'http://example.com';
        }
    }
    var_dump($url);

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // 文字列として出力する設定
    // curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_ALL); // デフォルトで全てのプロトコルは許可されているので、この設定は無意味。また設定してもgopherは刺さらなかった
    $html = curl_exec($ch);
    echo $purifier->purify($html); // 画面に表示
    curl_close($ch);
}

$app->run();