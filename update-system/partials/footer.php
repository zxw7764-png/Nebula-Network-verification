<!-- ============ 页脚 ============ -->
<footer class="footer">
  <div class="container footer-inner">
    <div class="footer-brand">
      <a class="brand" href="index.php">
        <span class="brand-logo brand-logo-img"><img src="assets/img/logo.png" alt="Nebula Logo"></span>
        <span class="brand-name">Nebula<span class="brand-sub">网络验证</span></span>
      </a>
      <p>为软件开发者提供安全、稳定、易用的网络验证与授权管理基础设施。</p>
    </div>
    <div class="footer-col">
      <h5>产品</h5>
      <a href="#product">产品构成</a>
      <a href="#features">核心功能</a>
      <a href="#security">安全防护</a>
      <a href="#update">版本更新</a>
    </div>
    <div class="footer-col">
      <h5>资源</h5>
      <a href="docs.html">开发文档</a>
      <a href="docs.html#d3-h0">接口协议</a>
      <a href="#quickstart">SDK 接入</a>
      <a href="#faq">常见问题</a>
    </div>
    <div class="footer-col">
      <h5>关于</h5>
      <a href="#frontends">门户 · 发卡</a>
      <a href="#contact">联系我们</a>
      <span>PHP 8.0+ · MySQL 5.7+</span>
      <span>VMProtect / Themida</span>
    </div>
  </div>
  <div class="container footer-bar">
    <span>© 2026 Nebula Verification System</span>
    <span>加密通信 · 签名校验 · 壳级保护 · 自动更新</span>
  </div>
</footer>

<button class="back-top" id="backTop" aria-label="返回顶部">
  <svg class="icon"><use href="#i-up"/></svg>
</button>

<!-- ============ 试用授权码弹窗（全站可用） ============ -->
<div class="trial-mask" id="trialMask">
  <div class="trial-box">
    <a href="javascript:void(0)" class="trial-close" onclick="trialHide()">&times;</a>
    <h3>免费获取试用授权码</h3>
    <label class="trial-lbl" for="trialDomain">你的部署域名（授权码将绑定此域名，一域一枚）</label>
    <input id="trialDomain" class="trial-dom" placeholder="例如 demo.example.com" autocomplete="off" spellcheck="false">
    <p id="trialDesc">填写域名并通过人机验证后自动签发一枚 7 天有效的试用授权码（每日限 3 枚）。
       安装向导第一页填入即可自动激活；未激活不影响系统功能使用。</p>
    <div class="trial-key">
      <input id="trialKey" readonly placeholder="点击「立即签发」获取">
      <button id="trialBtn" onclick="doTrial()">立即签发</button>
    </div>
    <div class="trial-msg" id="trialMsg"></div>
  </div>
</div>

<script>
var _trialCfg = null;
function getTrialKey(btn){
  document.getElementById('trialMask').classList.add('show');
  var m = document.getElementById('trialMsg'); m.className='trial-msg'; m.textContent='';
  if (btn) btn.blur();
  // 拉取后台配置的试用参数（天数/每日限领），动态渲染文案
  if (!_trialCfg) {
    fetch('api/license.php', {method:'GET'}).then(function(r){ return r.json(); })
      .then(function(r){
        if (r.code === 0 && r.data) _trialCfg = r.data;
        _refreshTrialDesc();
      }).catch(function(){});
  } else { _refreshTrialDesc(); }
}
function _refreshTrialDesc(){
  if (!_trialCfg) return;
  var d = _trialCfg.trial_days || 7, l = _trialCfg.trial_daily_limit || 3;
  var limitText = l === 0 ? '（暂未开放）' : '（每日限 ' + l + ' 枚）';
  document.getElementById('trialDesc').textContent =
    '填写域名并通过人机验证后自动签发一枚 ' + d + ' 天有效的试用授权码' + limitText + '。' +
    '安装向导第一页填入即可自动激活；未激活不影响系统功能使用。';
}
function trialHide(){ document.getElementById('trialMask').classList.remove('show'); }
// 仅当按下与松开都落在遮罩上才算「点击遮罩关闭」：
// 在弹窗内划选文字、拖动到弹窗外松手时不会误关弹窗。
var _trialMaskEl = document.getElementById('trialMask');
var _maskPressed = false;
_trialMaskEl.addEventListener('mousedown', function(e){ _maskPressed = (e.target === _trialMaskEl); });
_trialMaskEl.addEventListener('click', function(e){
  if (_maskPressed && e.target === _trialMaskEl) trialHide();
  _maskPressed = false;
});

// ---- 人机验证：工作量证明（hashcash 风格）----
// 目标：找到整数 x，使 sha256(nonce + '|' + x) 的前 difficulty 个十六进制字符为 0。
// 服务端用同一算法单次校验，成本极低；领取方需付出计算量，抬高脚本批量刷取代价。
function _sha256(ascii){
  function rr(v,a){return (v>>>a)|(v<<(32-a));}
  var mathPow=Math.pow, maxWord=mathPow(2,32), result='';
  var words=[], asciiBitLength=ascii.length*8;
  var hash=_sha256.h=_sha256.h||[], k=_sha256.k=_sha256.k||[], primeCounter=k.length;
  var isComposite={};
  for(var candidate=2; primeCounter<64; candidate++){
    if(!isComposite[candidate]){
      for(var i=0;i<313;i+=candidate){ isComposite[i]=candidate; }
      hash[primeCounter]=(mathPow(candidate,.5)*maxWord)|0;
      k[primeCounter++]=(mathPow(candidate,1/3)*maxWord)|0;
    }
  }
  ascii+='\x80';
  while(ascii.length%64-56){ ascii+='\x00'; }
  for(i=0;i<ascii.length;i++){
    var j=ascii.charCodeAt(i);
    if(j>>8){ return; }
    words[i>>2]|=j<<((3-i)%4)*8;
  }
  words[words.length]=(asciiBitLength/maxWord)|0;
  words[words.length]=asciiBitLength;
  for(j=0;j<words.length;){
    var w=words.slice(j,j+=16), oldHash=hash;
    hash=hash.slice(0,8);
    for(i=0;i<64;i++){
      var w15=w[i-15], w2=w[i-2];
      var a=hash[0], e=hash[4];
      var t1=hash[7]+(rr(e,6)^rr(e,11)^rr(e,25))+((e&hash[5])^((~e)&hash[6]))+k[i]
        +(w[i]=(i<16)?w[i]:(w[i-16]+(rr(w15,7)^rr(w15,18)^(w15>>>3))+w[i-7]+(rr(w2,17)^rr(w2,19)^(w2>>>10)))|0);
      var t2=(rr(a,2)^rr(a,13)^rr(a,22))+((a&hash[1])^(a&hash[2])^(hash[1]&hash[2]));
      hash=[(t1+t2)|0].concat(hash);
      hash[4]=(hash[4]+t1)|0;
    }
    for(i=0;i<8;i++){ hash[i]=(hash[i]+oldHash[i])|0; }
  }
  for(i=0;i<8;i++){
    for(j=3;j+1;j--){
      var b=(hash[i]>>(j*8))&255;
      result+=((b<16)?0:'')+b.toString(16);
    }
  }
  return result;
}
function _solvePow(nonce, difficulty){
  var full=Math.floor(difficulty/2), odd=difficulty%2, pre=nonce+'|';
  for(var x=0;x<8000000;x++){
    var h=_sha256(pre+x), ok=true;
    for(var i=0;i<full;i++){ if(h.charAt(i)!=='0'){ ok=false; break; } }
    if(ok&&odd){ if(h.charAt(full)!=='0'){ ok=false; } }
    if(ok){ return String(x); }
  }
  throw new Error('人机验证超时，请重试');
}

async function doTrial(){
  var m=document.getElementById('trialMsg');
  var btn=document.getElementById('trialBtn');
  var setMsg=function(t,cls){ m.className='trial-msg '+(cls||''); m.textContent=t; };
  var dom=(document.getElementById('trialDomain').value||'').trim();
  if(!dom){ setMsg('请先填写你的部署域名','err'); return; }
  btn.disabled=true;
  try{
    setMsg('正在进行人机验证…','ok');
    var cr=await (await fetch('api/license.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'challenge'})})).json();
    if(cr.code!==0||!cr.data){ throw new Error(cr.msg||'获取验证挑战失败'); }
    var c=cr.data;
    var x=_solvePow(c.nonce, c.difficulty);
    setMsg('签发中…','ok');
    var r=await (await fetch('api/license.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({
      action:'trial', domain:dom, nonce:c.nonce, ts:c.ts, difficulty:c.difficulty, sig:c.sig, x:x
    })})).json();
    if(r.code===0){
      document.getElementById('trialKey').value=r.data.license_key;
      var days=r.data.trial_days||7;
      setMsg('✅ 已签发并绑定 '+r.data.domain+'（'+new Date(r.data.expires_at*1000).toLocaleDateString()+' 到期，'+days+' 天有效），点击授权码即可复制','ok');
      var inp=document.getElementById('trialKey');
      inp.onclick=function(){ inp.select(); if(navigator.clipboard){ navigator.clipboard.writeText(inp.value); } };
    } else {
      setMsg(r.msg||'签发失败，请稍后重试','err');
    }
  }catch(e){
    setMsg(e&&e.message?e.message:'网络请求失败，请稍后重试','err');
  }finally{
    btn.disabled=false;
  }
}
</script>

<script src="assets/js/story.js?v=20261007a"></script>
<script src="assets/js/main.js?v=20261007a"></script>
</body>
</html>
