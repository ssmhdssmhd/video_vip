/**
 * kf.mjs - 无头浏览器视频直链提取器
 *
 * 两种模式:
 *   1. iframe 模式 - 访问公共解析源, 监听网络请求捕获 m3u8/mp4
 *   2. direct 模式 - 直接访问视频站点, 抓取真实 API 请求
 *
 * 用法: node scripts/kf.mjs <options>
 *   --url=视频地址         (必填)
 *   --mode=iframe|direct|all  (默认 all)
 *   --timeout=秒数         (默认 20)
 *   --site=腾讯|爱奇艺...  (指定模式, 可选)
 */

import { chromium } from 'playwright';
import { spawnSync } from 'node:child_process';

// ============ 解析源配置 (来自 video_vip.user.js) ============
const PARSE_APIS = [
  { name: '麒麟解析',   url: 'https://free.maccms.xyz/?url=' },
  { name: 'TXNQ解析',   url: 'https://bfq.txnp.cn/player?url=' },
  { name: '七哥解析',   url: 'https://jx.202617.xyz/tv.php?url=' },
  { name: 'fongmi解析', url: 'https://json.fongmi.cc/web?url=' },
  { name: '冰豆解析',   url: 'https://bd.jx.cn/?url=' },
  { name: 'HLS解析',    url: 'https://jx.hls.one/?url=' },
  { name: 'Player-JY',  url: 'https://jx.playerjy.com/?url=' },
];

// ============ 站点识别 ============
function detectSite(url) {
  const patterns = [
    { key: 'qq',    name: '腾讯视频', re: /v\.qq\.com|m\.v\.qq\.com/ },
    { key: 'bili',  name: 'Bilibili', re: /bilibili\.com/ },
    { key: 'iqiyi', name: '爱奇艺',   re: /iqiyi\.com|iq\.com/ },
    { key: 'youku', name: '优酷',     re: /youku\.com/ },
    { key: 'mgtv',  name: '芒果TV',   re: /mgtv\.com/ },
    { key: 'sohu',  name: '搜狐视频', re: /sohu\.com/ },
    { key: 'le',    name: '乐视',     re: /le\.com/ },
  ];
  for (const p of patterns) if (p.re.test(url)) return p;
  return { key: 'unknown', name: '未知', re: /.*/ };
}

// ============ 媒体 URL 判定 ============
function isMediaUrl(url) {
  if (!url) return false;
  // 直接媒体后缀
  if (/\.(m3u8|mp4|m4v|flv|ts|m4s|mpd|f4v|webm)(\?|#|$)/i.test(url)) return true;
  // 媒体 CDN 关键词
  if (/apdcdn|qiniu|douyucdn|tencent|iqiyi.*video|baidu.*video|bilibili.*video|youku.*video|sohu.*video|mgtv.*video/.test(url)) {
    if (/\?.*(format|codec|resolution|quality)/i.test(url)) return true;
    if (/\/video[s]?\//i.test(url)) return true;
  }
  return false;
}

// ============ 模拟用户访问单个解析源 ============
async function visitParseApi(browser, api, videoUrl, timeout) {
  const ctx = await browser.newContext({
    userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0.0.0 Safari/537.36',
    viewport: { width: 1920, height: 1080 },
    locale: 'zh-CN',
  });
  const page = await ctx.newPage();
  const results = [];
  const seen = new Set();

  page.on('response', async resp => {
    const u = resp.url();
    if (seen.has(u)) return;
    if (isMediaUrl(u)) {
      seen.add(u);
      results.push({
        source: api.name,
        url: u,
        status: resp.status(),
        type: u.includes('.m3u8') ? 'm3u8' : (u.includes('.mp4') ? 'mp4' : (u.includes('.mpd') ? 'mpd' : 'other')),
      });
    }
  });

  try {
    await page.goto(api.url + encodeURIComponent(videoUrl), {
      waitUntil: 'domcontentloaded',
      timeout: timeout * 1000,
    });
    // 模拟用户: 等待 + 滚动 + 点播放
    await page.evaluate(() => window.scrollTo(0, 300)).catch(()=>{});
    await new Promise(r => setTimeout(r, 3000));
    for (const sel of ['video', 'button[class*="play"]', '.play-btn', '[data-action="play"]']) {
      try { await page.click(sel, { timeout: 1500 }); } catch {}
    }
    await new Promise(r => setTimeout(r, 4000));
  } catch (e) {
    // 单个解析源失败不中断
  }
  await ctx.close();
  return results;
}

// ============ 直接访问视频站点抓真实请求 ============
async function visitSiteDirect(browser, videoUrl, timeout) {
  const ctx = await browser.newContext({
    userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0.0.0 Safari/537.36',
    viewport: { width: 1920, height: 1080 },
    locale: 'zh-CN',
  });
  const page = await ctx.newPage();
  const results = [];
  const apiCalls = [];
  const seen = new Set();

  page.on('response', async resp => {
    const u = resp.url();
    if (seen.has(u)) return;
    seen.add(u);
    if (isMediaUrl(u)) {
      results.push({
        source: 'direct',
        url: u,
        status: resp.status(),
        type: u.includes('.m3u8') ? 'm3u8' : (u.includes('.mp4') ? 'mp4' : 'other'),
      });
    } else if (/api|play|video|stream/i.test(u) && /\.json|\/\d+/.test(u) && !u.includes('.js')) {
      apiCalls.push({ url: u, status: resp.status() });
    }
  });

  try {
    await page.goto(videoUrl, { waitUntil: 'domcontentloaded', timeout: timeout * 1000 });
    await page.evaluate(() => window.scrollTo(0, 500)).catch(()=>{});
    await new Promise(r => setTimeout(r, 5000));
    for (const sel of ['video']) {
      try { await page.click(sel, { timeout: 1500 }); } catch {}
    }
    await new Promise(r => setTimeout(r, 3000));
  } catch (e) {}
  await ctx.close();
  return { media: results, apis: apiCalls };
}

// ============ 主流程 ============
async function main() {
  // 解析参数
  const args = process.argv.slice(2);
  const getArg = (k) => {
    const a = args.find(x => x.startsWith('--' + k + '='));
    return a ? a.slice(k.length + 3) : null;
  };

  const videoUrl = getArg('url') || '';
  const mode     = getArg('mode') || 'all';
  const timeout  = parseInt(getArg('timeout')) || 20;

  const out = {
    code: 0, msg: '',
    input_url: videoUrl,
    site: null,
    source: '',
    direct_url: '', direct_type: '',
    candidates: [],    // 解析源抓到的候选
    direct_media: [],  // 站点直连抓到的媒体 URL
    direct_apis: [],   // 站点直连的 API 请求
    error: null,
  };

  if (!videoUrl) { out.msg = '需要 --url='; console.log(JSON.stringify(out)); process.exit(1); }
  out.site = detectSite(videoUrl);

  const browser = await chromium.launch({
    headless: true,
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage'],
  });

  try {
    // ---- 模式 1: 解析源 iframe ----
    if (mode === 'all' || mode === 'iframe') {
      for (const api of PARSE_APIS) {
        const hits = await visitParseApi(browser, api, videoUrl, timeout);
        if (hits.length) out.candidates.push(...hits);
      }
    }

    // ---- 模式 2: 直接访问站点 ----
    if (mode === 'all' || mode === 'direct') {
      const direct = await visitSiteDirect(browser, videoUrl, timeout);
      out.direct_media = direct.media;
      out.direct_apis  = direct.apis;
    }

    // ---- 选最优先的直链 ----
    // 优先 m3u8 (解析源), 然后 mp4, 最后站点直连
    const allM3u8 = out.candidates.filter(c => c.type === 'm3u8');
    if (allM3u8.length) {
      const best = allM3u8[0];
      out.direct_url  = best.url;
      out.direct_type = 'm3u8';
      out.source      = best.source;
    } else if (out.candidates.length) {
      const best = out.candidates[0];
      out.direct_url  = best.url;
      out.direct_type = best.type;
      out.source      = best.source;
    } else if (out.direct_media.length) {
      // 选 m3u8 > mp4 > 其他
      const sorted = out.direct_media.sort((a,b) => {
        const rank = { m3u8:0, mp4:1, mpd:2, other:3 };
        return (rank[a.type]??3) - (rank[b.type]??3);
      });
      const best = sorted[0];
      out.direct_url  = best.url;
      out.direct_type = best.type;
      out.source      = `direct (${out.site.name})`;
    }

    out.code = out.direct_url ? 1 : 0;
    out.msg  = out.direct_url
      ? `成功 (${out.source})`
      : '未捕获到媒体 URL (解析源可能已失效, 或视频已下线)';

  } catch (e) {
    out.msg = '执行异常: ' + e.message;
    out.error = e.stack;
  } finally {
    await browser.close();
  }

  console.log(JSON.stringify(out, null, 2));
  process.exit(out.code ? 0 : 1);
}

main();
