<?php

namespace App\Services\Gateway;

/** Self-contained page: no third-party assets, original URI/query or application session. */
final class WafChallengePage
{
    public function __construct(private ?string $proofPath = null) {}

    public function assertAvailable(): void { $this->proof(); }

    public function render(array $challenge, string $cookie): array
    {
        $nonce = bin2hex(random_bytes(16));
        $settings = json_encode(array_intersect_key($challenge, array_flip(['type','nonce','difficulty','token','seconds'])) + ['cookie' => $cookie], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        $image = htmlspecialchars($challenge['image'], ENT_QUOTES, 'UTF-8');
        $captcha = $challenge['type'] === 'captcha' ? '<form id="verify"><img width="210" height="66" src="' . $image . '" alt="请输入图中的六位数字"><label for="answer">图片验证码</label><input id="answer" name="answer" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="off" required autofocus><button id="submit" type="submit">验证并继续</button></form>' : '<div class="spinner" aria-hidden="true"></div>';
        $proof = $this->proof();
        $html = <<<HTML
<!doctype html><html lang="zh-CN"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>安全验证 · Galaxy</title>
<style nonce="{$nonce}">*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:#f4f7fb;color:#26354a;font:15px/1.7 system-ui,sans-serif}.card{width:100%;max-width:460px;padding:40px;background:white;border:1px solid #e5eaf2;border-radius:16px;box-shadow:0 12px 40px #26354a0b}small{color:#758297;letter-spacing:2px}h1{font-size:25px;margin:12px 0}p{color:#758297}label,input,button{display:block;width:100%;margin-top:12px}input{padding:12px;font-size:20px;letter-spacing:8px;border:1px solid #cbd4e1;border-radius:8px}button{padding:12px;border:0;border-radius:8px;background:#3978f6;color:white;cursor:pointer;font-size:15px}button:disabled{opacity:.5;cursor:wait}.retry{background:#eef3fb;color:#50617a}.spinner{width:32px;height:32px;border:3px solid #e5edfc;border-top-color:#3978f6;border-radius:50%;animation:spin 1s linear infinite;margin:24px auto}@keyframes spin{to{transform:rotate(360deg)}}#status{min-height:26px;overflow-wrap:anywhere}img{max-width:100%;border-radius:8px}noscript{color:#be3e36}</style>
<main class="card"><small>GALAXY SECURITY</small><h1>正在确认访问安全</h1><p>验证通过后将继续访问当前页面。请保持此页面打开。</p>{$captcha}<p id="status" role="status" aria-live="polite">正在准备安全验证…</p><button class="retry" id="retry" type="button">刷新验证</button><noscript>请启用 JavaScript 和本站 Cookie 后刷新页面。</noscript></main>
<script nonce="{$nonce}">{$proof}</script><script nonce="{$nonce}">
const challenge={$settings};
const status=document.getElementById('status'),button=document.getElementById('submit');
document.getElementById('retry').onclick=()=>location.reload();
async function verify(answer){
 if(button)button.disabled=true;
 try{const response=await fetch(location.href,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','X-Galaxy-Waf-Challenge':'solve'},body:JSON.stringify({token:challenge.token,answer})});
 const result=await response.json();if(!response.ok||!result.ok)throw Error(result.message||'验证暂不可用，请稍后刷新');status.textContent='验证通过，正在继续访问…';location.reload();
 }catch(error){status.textContent=error.message||'网络异常，请刷新重试';if(button)button.disabled=false;}
}
async function start(){
 try{if(!navigator.cookieEnabled)throw Error('请允许本站 Cookie 后刷新页面');
 try{const key='galaxy-waf-loop:'+challenge.cookie;const previous=JSON.parse(sessionStorage.getItem(key)||'null');const attempts=previous&&Date.now()-previous.at<30000?previous.count+1:1;sessionStorage.setItem(key,JSON.stringify({at:Date.now(),count:attempts}));if(attempts>3)throw Error('无法保存通行凭据，请允许本站 Cookie 后刷新页面');}catch(error){if(error.message.startsWith('无法保存'))throw error;}
 if(challenge.type==='javascript'){status.textContent='正在进行浏览器计算验证…';await verify(await GalaxyWafProof.solve(challenge.nonce,challenge.difficulty,Date.now()+Math.min(120000,(challenge.seconds-5)*1000)));}
 else{status.textContent='请输入图片中的六位数字';document.getElementById('verify').onsubmit=event=>{event.preventDefault();verify(document.getElementById('answer').value)};}
 }catch(error){status.textContent=error.message||'验证暂不可用，请刷新重试';if(button)button.disabled=true;}
}
start();
</script></html>
HTML;
        return ['html' => $html, 'csp' => "default-src 'none'; script-src 'nonce-{$nonce}'; style-src 'nonce-{$nonce}'; img-src data:; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'"];
    }

    private function proof(): string
    {
        $path = $this->proofPath ?? BASE_PATH . '/storage/static/waf/browser-proof.js';
        $source = is_readable($path) ? @file_get_contents($path, length: 65537) : false;
        if ($source === false || trim($source) === '' || strlen($source) > 65536) throw new \App\Exception\AppException(503, '浏览器验证资源不可用');
        return $source;
    }
}
