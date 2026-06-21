<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>网页打印服务</title>
    <link rel="icon" type="image/x-icon" href="http://xinprint.zyshare.top/update/web_print/favicon.ico">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f0f2f5; min-height: 100vh; }
        .app { display: flex; height: 100vh; }
        .preview-panel { flex: 1; background: #1a1a2e; display: flex; flex-direction: column; }
        .preview-header { padding: 12px 16px; background: #16213e; color: white; display: flex; justify-content: space-between; align-items: center; }
        .preview-controls { display: flex; gap: 8px; align-items: center; }
        .preview-btn { background: #0f3460; border: none; color: white; padding: 6px 10px; border-radius: 4px; cursor: pointer; font-size: 13px; }
        .preview-btn:hover { background: #1a4a7a; }
        .page-info { color: #94a3b8; font-size: 13px; }
        .preview-content { flex: 1; overflow: auto; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .preview-placeholder { text-align: center; color: #64748b; }
        .preview-placeholder-icon { font-size: 60px; margin-bottom: 16px; opacity: 0.5; }
        #pdfCanvas { max-width: 100%; box-shadow: 0 4px 20px rgba(0,0,0,0.5); background: white; display: none; }
        .settings-panel { width: 480px; min-width: 280px; background: white; display: flex; flex-direction: column; box-shadow: -2px 0 10px rgba(0,0,0,0.1); overflow-y: auto; }
        .settings-header { padding: 16px 20px; background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white; }
        .settings-header h1 { font-size: 20px; margin-bottom: 4px; }
        .settings-header p { opacity: 0.9; font-size: 12px; }
        .settings-content { flex: 1; padding: 16px 20px; overflow-y: auto; }
        .section { margin-bottom: 16px; }
        .section-title { font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 10px; padding-left: 10px; border-left: 3px solid #4facfe; }
        .form-group { margin-bottom: 12px; }
        .form-group label { display: block; margin-bottom: 4px; font-size: 12px; color: #6b7280; }
        .form-group select, .form-group input { width: 100%; padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 13px; }
        .form-group select:focus, .form-group input:focus { outline: none; border-color: #4facfe; }
        .form-row { display: flex; gap: 10px; }
        .form-row .form-group { flex: 1; }
        .upload-area { border: 2px dashed #d1d5db; border-radius: 10px; padding: 20px; text-align: center; cursor: pointer; transition: all 0.3s; background: #fafafa; }
        .upload-area:hover { border-color: #4facfe; background: #f0f8ff; }
        .upload-area.has-file { border-color: #10b981; background: #ecfdf5; }
        .upload-icon { font-size: 32px; margin-bottom: 8px; }
        .file-info { background: #f3f4f6; border-radius: 6px; padding: 10px; margin-top: 10px; display: none; }
        .file-info.show { display: block; }
        .file-actions { display: flex; gap: 6px; margin-top: 8px; }
        .btn { padding: 8px 12px; border: none; border-radius: 6px; font-size: 12px; cursor: pointer; }
        .btn-convert { background: #f59e0b; color: white; flex: 1; }
        .btn-remove { background: #ef4444; color: white; }
        .btn-print { width: 100%; padding: 12px; background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white; font-size: 15px; font-weight: 600; margin-top: 16px; border-radius: 8px; }
        .btn-print:disabled { background: #d1d5db; cursor: not-allowed; }
        .message { padding: 10px; border-radius: 6px; margin-bottom: 12px; font-size: 13px; display: none; }
        .message.show { display: block; }
        .message.success { background: #ecfdf5; color: #065f46; }
        .message.error { background: #fef2f2; color: #991b1b; }
        .message.info { background: #eff6ff; color: #1e40af; }
        .loading { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(255,255,255,0.9); display: none; align-items: center; justify-content: center; z-index: 1000; }
        .loading.show { display: flex; }
        .spinner { width: 40px; height: 40px; border: 4px solid #e5e7eb; border-top-color: #4facfe; border-radius: 50%; animation: spin 1s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        input[type="file"] { display: none; }
        .orientation-options { display: flex; gap: 8px; }
        .orientation-btn { flex: 1; padding: 10px; border: 2px solid #e5e7eb; border-radius: 6px; background: white; cursor: pointer; text-align: center; font-size: 12px; }
        .orientation-btn.active { border-color: #4facfe; background: #eff6ff; }
        .scale-options { display: flex; gap: 6px; flex-wrap: wrap; }
        .scale-btn { padding: 6px 10px; border: 1px solid #e5e7eb; border-radius: 4px; background: white; cursor: pointer; font-size: 11px; }
        .scale-btn.active { background: #4facfe; color: white; border-color: #4facfe; }
        .convert-status { font-size: 11px; color: #059669; margin-top: 6px; display: none; }
        .convert-status.show { display: block; }
        @media (max-width: 800px) { .app { flex-direction: column; } .preview-panel { height: 40vh; } .settings-panel { width: 100%; } }
    </style>
</head>
<body>
<div class="app">
    <div class="preview-panel">
        <div class="preview-header">
            <span> 文档预览</span>
            <div class="preview-controls">
                <button class="preview-btn" id="prevPage"></button>
                <span class="page-info"><span id="currentPage">0</span> / <span id="totalPages">0</span></span>
                <button class="preview-btn" id="nextPage"></button>
                <button class="preview-btn" id="zoomOut"></button>
                <span class="page-info" id="zoomLevel">100%</span>
                <button class="preview-btn" id="zoomIn">+</button>
            </div>
        </div>
        <div class="preview-content">
            <div class="preview-placeholder" id="placeholder">
                <div class="preview-placeholder-icon"></div>
                <div>上传文件后在此预览</div>
            </div>
            <canvas id="pdfCanvas"></canvas>
        </div>
    </div>
    <div class="settings-panel">
        <div class="settings-header">
            <h1> 打印设置</h1>
            <p>配置打印选项</p>
        </div>
        <div class="settings-content">
            <div id="message" class="message"></div>
            <form id="printForm">
                <div class="section">
                    <div class="section-title">上传文件</div>
                    <div class="upload-area" id="uploadArea">
                        <div class="upload-icon"></div>
                        <div style="font-size:13px;">点击或拖拽文件<br><small>PDF/Word/Excel/图片</small></div>
                    </div>
                    <input type="file" id="fileInput" accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg,.gif,.bmp">
                    <div class="file-info" id="fileInfo">
                        <div><strong id="fileName"></strong></div>
                        <div style="font-size:11px;color:#666;" id="fileMeta"></div>
                        <div class="file-actions">
                            <button type="button" class="btn btn-convert" id="convertBtn" style="display:none;"> 转换PDF</button>
                            <button type="button" class="btn btn-remove" id="removeBtn"> 移除</button>
                        </div>
                        <div class="convert-status" id="convertStatus"> 已转换为PDF</div>
                    </div>
                </div>
                <div class="section">
                    <div class="section-title">打印机</div>
                    <div class="form-group">
                        <div style="display: flex; gap: 8px;">
                            <select id="printer" required style="flex: 1;"><option value="">-- 加载中... --</option></select>
                            <button type="button" class="btn" id="refreshBtn" title="重启web_print PHP服务器" style="padding: 8px 12px; background: #10b981; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 12px;">
                                🔄 重启
                            </button>
                        </div>
                    </div>
                </div>
                <div class="section">
                    <div class="section-title">打印范围</div>
                    <div class="form-group">
                        <select id="pageRange">
                            <option value="all">全部页面</option>
                            <option value="current">当前页面</option>
                            <option value="custom">自定义范围</option>
                        </select>
                    </div>
                    <div class="form-group" id="customRangeDiv" style="display:none;">
                        <input type="text" id="pageRangeText" placeholder="例如: 1-5, 8, 11-13">
                    </div>
                </div>
                <div class="section">
                    <div class="section-title">基本设置</div>
                    <div class="form-row">
                        <div class="form-group"><label>份数</label><input type="number" id="copies" value="1" min="1" max="999"></div>
                        <div class="form-group"><label>逐份</label><select id="collate"><option value="true">是</option><option value="false">否</option></select></div>
                    </div>
                </div>
                <div class="section">
                    <div class="section-title">方向</div>
                    <div class="orientation-options">
                        <div class="orientation-btn active" data-value="portrait"> 纵向</div>
                        <div class="orientation-btn" data-value="landscape"> 横向</div>
                    </div>
                    <input type="hidden" id="orientation" value="portrait">
                </div>
                <div class="section">
                    <div class="section-title">纸张</div>
                    <div class="form-row">
                        <div class="form-group"><label>大小</label>
                            <select id="paperSize"><option value="A4">A4</option><option value="A3">A3</option><option value="A5">A5</option><option value="B5">B5</option><option value="Letter">Letter</option><option value="16K">16K</option></select>
                        </div>
                        <div class="form-group"><label>来源</label>
                            <select id="paperSource"><option value="auto">自动</option><option value="tray1">纸盒1</option><option value="manual">手动</option></select>
                        </div>
                    </div>
                </div>
                <div class="section">
                    <div class="section-title">缩放</div>
                    <div class="scale-options">
                        <button type="button" class="scale-btn active" data-value="100">100%</button>
                        <button type="button" class="scale-btn" data-value="fit">适合</button>
                        <button type="button" class="scale-btn" data-value="75">75%</button>
                        <button type="button" class="scale-btn" data-value="50">50%</button>
                        <button type="button" class="scale-btn" data-value="150">150%</button>
                    </div>
                    <input type="hidden" id="scale" value="100">
                </div>
                <div class="section">
                    <div class="section-title">其他</div>
                    <div class="form-row">
                        <div class="form-group"><label>双面</label>
                            <select id="sides"><option value="one-sided">单面</option><option value="two-sided-long-edge">双面(长边)</option><option value="two-sided-short-edge">双面(短边)</option></select>
                        </div>
                        <div class="form-group"><label>颜色</label>
                            <select id="colorMode"><option value="auto">自动</option><option value="color">彩色</option><option value="monochrome">黑白</option></select>
                        </div>
                    </div>
                </div>
                <button type="button" class="btn btn-print" id="submitBtn" disabled> 开始打印</button>
            </form>
        </div>
    </div>
</div>
<div class="loading" id="loading"><div class="spinner"></div></div>
<script>
if(typeof pdfjsLib!=='undefined'){pdfjsLib.GlobalWorkerOptions.workerSrc='https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';}
let selectedFile=null,pdfDoc=null,currentPage=1,totalPages=0,currentZoom=1.0,convertedFile=null,fileExt='';
const uploadArea=document.getElementById('uploadArea'),fileInput=document.getElementById('fileInput'),fileInfo=document.getElementById('fileInfo'),
fileName=document.getElementById('fileName'),fileMeta=document.getElementById('fileMeta'),convertBtn=document.getElementById('convertBtn'),
removeBtn=document.getElementById('removeBtn'),convertStatus=document.getElementById('convertStatus'),printerSelect=document.getElementById('printer'),
submitBtn=document.getElementById('submitBtn'),message=document.getElementById('message'),loading=document.getElementById('loading'),
pdfCanvas=document.getElementById('pdfCanvas'),placeholder=document.getElementById('placeholder');

async function loadPrinters(){
    try{const r=await fetch('api.php?action=printers');const d=await r.json();printerSelect.innerHTML='';
    if(d.success&&d.printers&&d.printers.length>0){d.printers.forEach(p=>{const o=document.createElement('option');o.value=p.name;o.textContent=p.name+(p.is_default?' (默认)':'');if(p.is_default)o.selected=true;printerSelect.appendChild(o);});updateBtn();}
    else{printerSelect.innerHTML='<option value="">未找到打印机</option>';}}catch(e){printerSelect.innerHTML='<option value="">加载失败</option>';}}

function updateBtn(){submitBtn.disabled=!selectedFile||!printerSelect.value;}
function showMsg(t,type){message.textContent=t;message.className='message show '+type;if(type==='success')setTimeout(()=>message.className='message',5000);}
function showLoading(){loading.classList.add('show');}
function hideLoading(){loading.classList.remove('show');}
function formatSize(b){if(b<1024)return b+' B';if(b<1024*1024)return(b/1024).toFixed(1)+' KB';return(b/(1024*1024)).toFixed(2)+' MB';}

async function renderPage(num){if(!pdfDoc)return;const page=await pdfDoc.getPage(num);const vp=page.getViewport({scale:currentZoom});
pdfCanvas.width=vp.width;pdfCanvas.height=vp.height;await page.render({canvasContext:pdfCanvas.getContext('2d'),viewport:vp}).promise;
document.getElementById('currentPage').textContent=num;document.getElementById('totalPages').textContent=totalPages;currentPage=num;}

async function loadPdf(file){if(typeof pdfjsLib==='undefined')return;try{const ab=await file.arrayBuffer();pdfDoc=await pdfjsLib.getDocument({data:ab}).promise;
totalPages=pdfDoc.numPages;currentPage=1;placeholder.style.display='none';pdfCanvas.style.display='block';await renderPage(1);}catch(e){console.error(e);}}

function handleFile(file){if(!file)return;const exts=['pdf','doc','docx','xls','xlsx','png','jpg','jpeg','gif','bmp'];
fileExt=file.name.split('.').pop().toLowerCase();if(!exts.includes(fileExt)){showMsg('不支持的文件格式','error');return;}
if(file.size>500*1024*1024){showMsg('文件不能超过500MB','error');return;}
selectedFile=file;convertedFile=null;fileName.textContent=file.name;fileMeta.textContent=formatSize(file.size)+' | '+fileExt.toUpperCase();
fileInfo.classList.add('show');uploadArea.classList.add('has-file');convertBtn.style.display=fileExt!=='pdf'?'block':'none';
convertStatus.classList.remove('show');
if(fileExt==='pdf'){loadPdf(file);}else{placeholder.innerHTML='<div class="preview-placeholder-icon"></div><div>'+file.name+'</div><div style="font-size:12px;margin-top:8px;">点击"转换PDF"后可预览</div>';placeholder.style.display='block';pdfCanvas.style.display='none';}
updateBtn();}

function removeFile(){selectedFile=null;convertedFile=null;pdfDoc=null;fileInput.value='';fileInfo.classList.remove('show');
uploadArea.classList.remove('has-file');convertStatus.classList.remove('show');placeholder.innerHTML='<div class="preview-placeholder-icon"></div><div>上传文件后在此预览</div>';
placeholder.style.display='block';pdfCanvas.style.display='none';document.getElementById('currentPage').textContent='0';document.getElementById('totalPages').textContent='0';updateBtn();}

async function convertToPdf(){if(!selectedFile||fileExt==='pdf')return;showLoading();const fd=new FormData();fd.append('action','convert');fd.append('file',selectedFile);
try{const r=await fetch('api.php',{method:'POST',body:fd});const d=await r.json();
if(d.success&&d.pdf_url){const pr=await fetch(d.pdf_url);const blob=await pr.blob();convertedFile=new File([blob],selectedFile.name.replace(/\.[^.]+$/,'.pdf'),{type:'application/pdf'});
convertStatus.classList.add('show');convertBtn.style.display='none';showMsg('转换成功','success');loadPdf(convertedFile);}else{showMsg('转换失败: '+(d.error||'未知错误'),'error');}}
catch(e){showMsg('转换失败','error');}finally{hideLoading();}}

uploadArea.addEventListener('click',()=>fileInput.click());
fileInput.addEventListener('change',e=>handleFile(e.target.files[0]));
removeBtn.addEventListener('click',removeFile);
convertBtn.addEventListener('click',convertToPdf);
uploadArea.addEventListener('dragover',e=>{e.preventDefault();uploadArea.style.borderColor='#4facfe';});
uploadArea.addEventListener('dragleave',()=>uploadArea.style.borderColor='');
uploadArea.addEventListener('drop',e=>{e.preventDefault();uploadArea.style.borderColor='';handleFile(e.dataTransfer.files[0]);});

document.getElementById('pageRange').addEventListener('change',e=>{document.getElementById('customRangeDiv').style.display=e.target.value==='custom'?'block':'none';});
document.querySelectorAll('.orientation-btn').forEach(b=>b.addEventListener('click',()=>{document.querySelectorAll('.orientation-btn').forEach(x=>x.classList.remove('active'));b.classList.add('active');document.getElementById('orientation').value=b.dataset.value;}));
document.querySelectorAll('.scale-btn').forEach(b=>b.addEventListener('click',()=>{document.querySelectorAll('.scale-btn').forEach(x=>x.classList.remove('active'));b.classList.add('active');document.getElementById('scale').value=b.dataset.value;}));
document.getElementById('prevPage').addEventListener('click',()=>{if(currentPage>1)renderPage(currentPage-1);});
document.getElementById('nextPage').addEventListener('click',()=>{if(currentPage<totalPages)renderPage(currentPage+1);});
document.getElementById('zoomIn').addEventListener('click',()=>{currentZoom=Math.min(3,currentZoom+0.25);document.getElementById('zoomLevel').textContent=Math.round(currentZoom*100)+'%';if(pdfDoc)renderPage(currentPage);});
document.getElementById('zoomOut').addEventListener('click',()=>{currentZoom=Math.max(0.25,currentZoom-0.25);document.getElementById('zoomLevel').textContent=Math.round(currentZoom*100)+'%';if(pdfDoc)renderPage(currentPage);});
printerSelect.addEventListener('change',updateBtn);

// 刷新按钮事件处理
document.getElementById('refreshBtn').addEventListener('click', async () => {
    const refreshBtn = document.getElementById('refreshBtn');
    const originalText = refreshBtn.innerHTML;
    
    try {
        refreshBtn.disabled = true;
        refreshBtn.innerHTML = '⏳ 重启中...';
        showMsg('正在重启web_print PHP服务器...', 'info');
        
        const response = await fetch('api.php?action=restart', { method: 'POST' });
        const data = await response.json();
        
        if (data.success) {
            let msg = 'web_print服务重启成功！\n\n执行的操作：\n';
            if (data.actions && data.actions.length > 0) {
                data.actions.forEach(action => {
                    msg += '✓ ' + action + '\n';
                });
            }
            msg += '\n页面将在3秒后自动刷新...';
            showMsg(msg, 'success');
            // 等待3秒后刷新页面
            setTimeout(() => {
                window.location.reload();
            }, 3000);
        } else {
            showMsg('重启失败: ' + (data.error || '未知错误'), 'error');
        }
    } catch (error) {
        showMsg('重启请求失败: ' + error.message, 'error');
    } finally {
        refreshBtn.disabled = false;
        refreshBtn.innerHTML = originalText;
    }
});

submitBtn.addEventListener('click',async()=>{if(!selectedFile||!printerSelect.value)return;
const fd=new FormData();fd.append('action','print');fd.append('file',convertedFile||selectedFile);fd.append('printer',printerSelect.value);
fd.append('copies',document.getElementById('copies').value);fd.append('paper_size',document.getElementById('paperSize').value);
fd.append('orientation',document.getElementById('orientation').value);fd.append('sides',document.getElementById('sides').value);
fd.append('color_mode',document.getElementById('colorMode').value);fd.append('scale',document.getElementById('scale').value);
fd.append('collate',document.getElementById('collate').value);
const rt=document.getElementById('pageRange').value;
if(rt==='current')fd.append('page_range_text',String(currentPage));
else if(rt==='custom')fd.append('page_range_text',document.getElementById('pageRangeText').value);
fd.append('total_pages',totalPages);submitBtn.disabled=true;showLoading();
try{const r=await fetch('api.php',{method:'POST',body:fd});const d=await r.json();
if(d.success){showMsg(' 打印任务已提交！任务ID: '+d.job_id,'success');removeFile();}
else{showMsg(' 打印失败: '+d.error,'error');}}catch(e){showMsg('提交失败','error');}finally{hideLoading();updateBtn();}});

loadPrinters();
</script>
</body>
</html>
