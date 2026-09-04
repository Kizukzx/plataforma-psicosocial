const API_BASE = '..';

async function apiRequest(path, method='GET', body=null, extra={}) {
  const options={method, credentials:'include', headers:{}};
  const csrf=extra.csrf || window.sessionData?.csrf_token || (window.sessionData?.usuario ? window.sessionData.csrf_token : null);
  if (csrf && !['GET','HEAD'].includes(method)) options.headers['X-CSRF-Token']=csrf;
  if (body instanceof FormData) { options.body=body; }
  else if (body!==null) { options.headers['Content-Type']='application/json'; options.body=JSON.stringify(body); }
  const res=await fetch(API_BASE+path,options);
  let data=null; try{data=await res.json();}catch{throw new Error('Resposta inválida do servidor.');}
  if(!res.ok || !data.sucesso) throw new Error(data.mensagem || 'Erro na requisição.');
  return data.dados;
}
