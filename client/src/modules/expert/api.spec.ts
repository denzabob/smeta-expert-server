import type { AxiosInstance } from 'axios'
import { describe, expect, it, vi } from 'vitest'
import { createExpertApi, isDemoProjectId, isExpertMaterialContextError, mapExpertApiError, mapExpertProject, mapFinding, mapMaterial, mapMessage, toProjectPayload } from './api'

describe('Expert persistence mapping', () => {
  it('isolates exactly two demo ids', () => {
    expect(isDemoProjectId('demo-commodity')).toBe(true)
    expect(isDemoProjectId('demo-construction')).toBe(true)
    expect(isDemoProjectId('demo-anything')).toBe(false)
    expect(isDemoProjectId('550e8400-e29b-41d4-a716-446655440000')).toBe(false)
  })
  it('maps backend project without mock state or numeric ids', () => {
    const project = mapExpertProject({ public_id:'uuid',name:'Проект',domain:'other',work_type:'report',status:'active',created_at:'2026-09-12T10:00:00Z',updated_at:'2026-09-12T11:00:00Z',counts:{research_objects:2,conversations:3,materials:4,findings:5},research_questions:[{id:'q2',order:2,text:'Второй'},{id:'q1',order:1,text:'Первый'}] })
    expect(project.id).toBe('uuid'); expect(project.questions).toEqual(['Первый','Второй']); expect(project.counts).toEqual({researchObjects:2,conversations:3,materials:4,findings:5}); expect(project.normatives).toEqual([])
  })
  it('maps create labels to machine values and structured questions', () => {
    const payload=toProjectPayload({title:' P ',direction:'Товароведческое',workType:'Досудебное исследование',customer:'',object:'Объект',address:'',researchDate:'',questions:['Q']})
    expect(payload).toMatchObject({name:'P',domain:'commodity',work_type:'pretrial_research',research_questions:[expect.objectContaining({order:1,text:'Q'})],initial_research_object:{name:'Объект',sort_order:0}})
  })
  it('provides stable fallback error mapping', () => { expect(mapExpertApiError(new Error('x'))).toEqual({code:undefined,message:'Не удалось выполнить запрос.',validationErrors:{}}) })
  it('maps private material metadata without exposing a storage path', () => {
    const material=mapMaterial({public_id:'m1',original_name:'evidence.pdf',mime_type:'application/pdf',extension:'pdf',size:2048,category:'document',status:'uploaded',created_at:'2026-09-12T10:00:00Z'})
    expect(material).toMatchObject({id:'m1',name:'evidence.pdf',kind:'document',size:'2.0 КБ',status:'Загружен'})
    expect(material).not.toHaveProperty('storagePath')
  })
  it('restores persisted user attachments and the retry ID from history', () => {
    const message = mapMessage({
      public_id: 'user-1', role: 'user', content: 'Прочитай', created_at: '2026-09-19T10:00:00Z',
      metadata: { client_message_id: 'retry-1' },
      attachments: [{ material_public_id: 'material-1', original_name: 'Акт.pdf', mime_type: 'application/pdf', size: 2048, kind: 'document', available: false }],
    })
    expect(message.clientMessageId).toBe('retry-1')
    expect(message.attachments).toEqual([expect.objectContaining({ id: 'material-1', name: 'Акт.pdf', available: false })])
    expect(message.attachments?.[0]).not.toHaveProperty('storagePath')
  })
  it('maps only the current user feedback and writes it through the scoped message endpoint', async () => {
    const mapped = mapMessage({
      public_id: 'assistant-1', role: 'assistant', content: 'Ответ', created_at: '2026-09-20T10:00:00Z',
      feedback: { rating: 'negative', reason_code: 'too_slow', comment: 'Долго.' },
    })
    expect(mapped.feedback).toEqual({ rating: 'negative', reasonCode: 'too_slow', comment: 'Долго.' })
    const put = vi.fn().mockResolvedValue({ data: { rating: 'positive', reason_code: null, comment: null } })
    const remove = vi.fn().mockResolvedValue({ data: null })
    const client = createExpertApi({ put, delete: remove } as unknown as AxiosInstance)
    await expect(client.saveMessageFeedback('assistant/1', { rating: 'positive' })).resolves.toEqual({ rating: 'positive', reasonCode: null, comment: null })
    await client.deleteMessageFeedback('assistant/1')
    expect(put).toHaveBeenCalledWith('/api/expert/messages/assistant%2F1/feedback', { rating: 'positive', reason_code: null, comment: null })
    expect(remove).toHaveBeenCalledWith('/api/expert/messages/assistant%2F1/feedback')
  })
  it('uses a safe display name when old material data has no original filename', () => {
    const material=mapMaterial({public_id:'m1',original_name:'',mime_type:'application/pdf',extension:'pdf',size:2048,category:'document',status:'uploaded',created_at:'2026-09-12T10:00:00Z'})
    expect(material.name).toBe('Материал без названия.pdf')
    expect(material.name).not.toContain('storage')
  })
  it('does not expose a legacy UUID storage filename as a material name', () => {
    const material=mapMaterial({public_id:'m1',original_name:'6de452c6-942f-45ed-8ea4-429dc4ba384b.jpeg',mime_type:'image/jpeg',extension:'jpeg',size:2048,category:'image',status:'uploaded',created_at:'2026-09-12T10:00:00Z'})
    expect(material.name).toBe('Материал без названия.jpeg')
  })
  it('maps finding bindings by public ids', () => {
    const finding=mapFinding({public_id:'f1',type:'measurement',title:'Размер',value:'120',unit:'мм',status:'expert_confirmed',research_object:{public_id:'o1',name:'Стол',sort_order:0},materials:[{public_id:'m1',original_name:'photo.jpg',mime_type:'image/jpeg',extension:'jpg',size:100,category:'image',status:'uploaded',created_at:'2026-09-12T10:00:00Z'}],created_at:'2026-09-12T10:00:00Z'})
    expect(finding).toMatchObject({id:'f1',researchObjectId:'o1',materialIds:['m1'],measurement:'120 мм',status:'Подтверждено экспертом'})
  })
  it('unwraps collection endpoints through the injected HTTP client', async () => {
    const get=vi.fn().mockResolvedValue({data:{data:[{public_id:'p1',name:'Проект',domain:'commodity',work_type:'report',status:'active',created_at:'2026-09-12T10:00:00Z',updated_at:'2026-09-12T10:00:00Z'}]}})
    const client=createExpertApi({get} as unknown as AxiosInstance)
    await expect(client.listProjects()).resolves.toEqual([expect.objectContaining({id:'p1',title:'Проект'})])
    expect(get).toHaveBeenCalledWith('/api/expert/projects')
  })
  it('passes upload and download progress through the existing API client', async () => {
    const post=vi.fn(async (_url, _form, config) => {
      config.onUploadProgress({loaded:50,total:100})
      return {data:{public_id:'m1',original_name:'evidence.pdf',mime_type:'application/pdf',extension:'pdf',size:100,category:'document',status:'uploaded',created_at:'2026-09-12T10:00:00Z'}}
    })
    const get=vi.fn(async (_url, config) => {
      config.onDownloadProgress({loaded:30,total:100})
      return {data:{} as Blob}
    })
    const client=createExpertApi({post,get} as unknown as AxiosInstance)
    const uploadProgress:number[]=[]
    const downloadProgress:(number|null)[]=[]

    await client.uploadMaterial('p1', {name:'evidence.pdf'} as File, {onProgress:(value) => uploadProgress.push(value)})
    await client.downloadMaterial('m1', {onProgress:(value) => downloadProgress.push(value)})

    expect(uploadProgress).toEqual([50])
    expect(downloadProgress).toEqual([30])
  })
  it('requests private thumbnails through a dedicated endpoint', async () => {
    const get = vi.fn().mockResolvedValue({ data: {} as Blob })
    const client = createExpertApi({ get } as unknown as AxiosInstance)

    await client.getMaterialThumbnail('m/1')

    expect(get).toHaveBeenCalledWith('/api/expert/materials/m%2F1/thumbnail', { responseType: 'blob' })
  })
  it('sends the content-only chat body with a stable retry header and maps both messages', async () => {
    const post=vi.fn().mockResolvedValue({data:{
      user_message:{public_id:'message-1',role:'user',content:'Проверить',created_at:'2026-09-12T10:00:00Z'},
      assistant_message:{public_id:'message-2',role:'assistant',content:'Ответ',created_at:'2026-09-12T10:00:01Z'},
    }})
    const client=createExpertApi({post} as unknown as AxiosInstance)

    await expect(client.sendMessage('conversation-1', 'Проверить', '550e8400-e29b-41d4-a716-446655440000')).resolves.toEqual({
      userMessage: expect.objectContaining({id:'message-1',role:'user'}),
      assistantMessage: expect.objectContaining({id:'message-2',role:'assistant',text:'Ответ'}),
    })

    expect(post).toHaveBeenCalledWith(
      '/api/expert/conversations/conversation-1/messages',
      {content:'Проверить'},
      {headers:{'X-Expert-Message-Id':'550e8400-e29b-41d4-a716-446655440000'}},
    )
  })

  it('sends public material UUIDs only when the immutable snapshot is not empty', async () => {
    const post=vi.fn().mockResolvedValue({data:{
      user_message:{public_id:'message-1',role:'user',content:'Проверить',created_at:'2026-09-12T10:00:00Z'},
      assistant_message:{public_id:'message-2',role:'assistant',content:'Ответ',created_at:'2026-09-12T10:00:01Z'},
    }})
    const client=createExpertApi({post} as unknown as AxiosInstance)

    await client.sendMessage(
      'conversation-1',
      'Проверить',
      '550e8400-e29b-41d4-a716-446655440000',
      ['550e8400-e29b-41d4-a716-446655440001', '550e8400-e29b-41d4-a716-446655440002'],
    )

    expect(post).toHaveBeenCalledWith(
      '/api/expert/conversations/conversation-1/messages',
      {content:'Проверить',material_public_ids:['550e8400-e29b-41d4-a716-446655440001', '550e8400-e29b-41d4-a716-446655440002']},
      {headers:{'X-Expert-Message-Id':'550e8400-e29b-41d4-a716-446655440000'}},
    )
  })

  it('reads and replaces only the selected conversation material IDs', async () => {
    const active = [{ id: 'material-1', name: 'Акт.pdf', mime_type: 'application/pdf' }]
    const get = vi.fn().mockResolvedValue({ data: { active_materials: active } })
    const put = vi.fn().mockResolvedValue({ data: { active_materials: active } })
    const client = createExpertApi({ get, put } as unknown as AxiosInstance)

    await expect(client.getConversationContext('project/1', 'chat/1')).resolves.toEqual(active)
    await expect(client.updateConversationContext('project/1', 'chat/1', ['material-1'])).resolves.toEqual(active)
    const path = '/api/expert/projects/project%2F1/conversations/chat%2F1/context'
    expect(get).toHaveBeenCalledWith(path)
    expect(put).toHaveBeenCalledWith(path, { active_material_ids: ['material-1'] })
  })

  it('preserves machine-readable context errors without parsing their Russian text', () => {
    const error = {
      isAxiosError: true,
      response: {
        status: 422,
        data: {code:'material_context_extraction_failed',message:'Не удалось извлечь текст.'},
      },
    }

    const mapped = mapExpertApiError(error)

    expect(mapped).toMatchObject({status:422,code:'material_context_extraction_failed',message:'Не удалось извлечь текст.'})
    expect(isExpertMaterialContextError(mapped.code)).toBe(true)
    expect(isExpertMaterialContextError('material_context_temporarily_disabled')).toBe(true)
    expect(isExpertMaterialContextError('vision_not_supported')).toBe(true)
    expect(isExpertMaterialContextError('material_not_supported')).toBe(true)

    const conflict = mapExpertApiError({
      isAxiosError: true,
      response: {status:409, data:{code:'expert_request_conflict', message:'Snapshot изменён.'}},
    })

    expect(conflict).toMatchObject({status:409,code:'expert_request_conflict',message:'Snapshot изменён.'})
  })

  it('dispatches only versioned activity and dedicated safe-summary stream events', async () => {
    vi.stubGlobal('document', { cookie: '' })
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response([
      'event: run\ndata: {"version":1,"run_id":"run-1","user_message":{"public_id":"u1","role":"user","content":"Вопрос","created_at":"2026-09-15T10:00:00Z"}}\n\n',
      'event: activity\ndata: {"version":1,"run_id":"run-1","seq":1,"activity_id":"a1","code":"pdf.ocr_cache.hit","status":"completed","category":"material","detail":"C:\\\\private\\\\scan.pdf","raw":"OCR-SECRET"}\n\n',
      'event: reasoning\ndata: {"version":1,"run_id":"run-1","seq":1,"text":"RAW-CHAIN-OF-THOUGHT"}\n\n',
      'event: reasoning_summary\ndata: {"version":1,"run_id":"run-1","seq":1,"text":"Проверен OCR-кеш.","reasoning":"RAW-CHAIN-OF-THOUGHT","final":true}\n\n',
      'event: done\ndata: {"version":1}\n\n',
    ].join(''), { status: 200, headers: { 'Content-Type': 'text/event-stream' } })))
    const activity: unknown[] = []
    const summaries: unknown[] = []
    const client = createExpertApi({ getUri: () => 'https://expert.test' } as unknown as AxiosInstance)

    await client.streamMessage('conversation-1', 'Вопрос', 'message-1', [], {
      onRun: () => undefined,
      onDelta: () => undefined,
      onActivity: (event) => activity.push(event),
      onReasoningSummary: (event) => summaries.push(event),
      onDone: () => undefined,
      onCancelled: () => undefined,
      onError: () => undefined,
    })

    expect(activity).toEqual([expect.objectContaining({ code: 'pdf.ocr_cache.hit', detail: 'scan.pdf' })])
    expect(summaries).toEqual([expect.objectContaining({ text: 'Проверен OCR-кеш.', final: true })])
    expect(JSON.stringify({ activity, summaries })).not.toContain('RAW-CHAIN-OF-THOUGHT')
    expect(JSON.stringify({ activity, summaries })).not.toContain('OCR-SECRET')
    vi.unstubAllGlobals()
  })

  it('posts the streaming snapshot with the PDF public UUID and stable message header', async () => {
    vi.stubGlobal('document', { cookie: '' })
    const fetchMock = vi.fn().mockResolvedValue(new Response('event: done\ndata: {"version":1}\n\n', { status: 200, headers: { 'Content-Type': 'text/event-stream' } }))
    vi.stubGlobal('fetch', fetchMock)
    const client = createExpertApi({ getUri: ({ url }: { url: string }) => `https://expert.test${url}` } as unknown as AxiosInstance)
    await client.streamMessage('conversation-1', 'Проверь PDF', 'request-1', ['550e8400-e29b-41d4-a716-446655440001'], {
      onRun: () => undefined, onDelta: () => undefined, onDone: () => undefined, onCancelled: () => undefined, onError: () => undefined,
    })

    expect(fetchMock).toHaveBeenCalledWith('https://expert.test/api/expert/conversations/conversation-1/messages/stream', expect.objectContaining({
      method: 'POST',
      headers: expect.objectContaining({ 'X-Expert-Message-Id': 'request-1' }),
      body: JSON.stringify({ content: 'Проверь PDF', material_public_ids: ['550e8400-e29b-41d4-a716-446655440001'] }),
    }))
    vi.unstubAllGlobals()
  })

  it('shows a safe contextual message for a classified provider failure', async () => {
    vi.stubGlobal('document', { cookie: '' })
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('event: error\ndata: {"version":1,"code":"provider_auth_failed"}\n\n', { status: 200, headers: { 'Content-Type': 'text/event-stream' } })))
    const errors: string[] = []
    const client = createExpertApi({ getUri: () => 'https://expert.test' } as unknown as AxiosInstance)
    await client.streamMessage('conversation-1', 'Вопрос', 'request-1', [], {
      onRun: () => undefined, onDelta: () => undefined, onDone: () => undefined, onCancelled: () => undefined,
      onError: (error) => errors.push(`${error.code}: ${error.message}`),
    })

    expect(errors).toEqual(['provider_auth_failed: Провайдер AI недоступен из-за настройки доступа.'])
    vi.unstubAllGlobals()
  })

  it('rejects a stream that closes without a terminal event', async () => {
    vi.stubGlobal('document', { cookie: '' })
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('event: run\ndata: {"run_id":"run-1","user_message":{"public_id":"u1","role":"user","content":"Вопрос","created_at":"2026-09-20T10:00:00Z"}}\n\n', { status: 200 })))
    const client = createExpertApi({ getUri: () => 'https://expert.test' } as unknown as AxiosInstance)
    await expect(client.streamMessage('conversation-1', 'Вопрос', 'request-1', [], {
      onRun: () => undefined, onDelta: () => undefined, onDone: () => undefined, onCancelled: () => undefined, onError: () => undefined,
    })).rejects.toMatchObject({ mapped: { code: 'stream_eof_without_terminal' } })
    vi.unstubAllGlobals()
  })

  it('exposes only safe terminal diagnostics for production support', async () => {
    vi.stubGlobal('document', { cookie: '' })
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('event: error\ndata: {"version":1,"code":"expert_stream_interrupted","error_code":"provider_timeout","run_id":"run-48217","retryable":true,"last_activity_code":"model.request.started","raw":"SECRET-UPSTREAM-BODY"}\n\n', { status: 200, headers: { 'Content-Type': 'text/event-stream' } })))
    const errors: unknown[] = []
    const client = createExpertApi({ getUri: () => 'https://expert.test' } as unknown as AxiosInstance)

    await client.streamMessage('conversation-1', 'Вопрос', 'request-1', [], {
      onRun: () => undefined, onDelta: () => undefined, onDone: () => undefined, onCancelled: () => undefined,
      onError: (error) => errors.push(error),
    })

    expect(errors).toEqual([expect.objectContaining({
      code: 'provider_timeout',
      diagnostic: { runId: 'run-48217', errorCode: 'provider_timeout', retryable: true, lastActivityCode: 'model.request.started' },
    })])
    expect(JSON.stringify(errors)).not.toContain('SECRET-UPSTREAM-BODY')
    vi.unstubAllGlobals()
  })

  it('dispatches each assistant delta before done without buffering the response', async () => {
    vi.stubGlobal('document', { cookie: '' })
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response([
      'event: run\ndata: {"version":1,"run_id":"run-1","user_message":{"public_id":"u1","role":"user","content":"Вопрос","created_at":"2026-09-15T10:00:00Z"}}\n\n',
      'event: delta\ndata: {"version":1,"seq":1,"text":"Первая часть. "}\n\n',
      'event: delta\ndata: {"version":1,"seq":2,"text":"Вторая часть."}\n\n',
      'event: done\ndata: {"version":1}\n\n',
    ].join(''), { status: 200, headers: { 'Content-Type': 'text/event-stream' } })))
    const received: string[] = []
    const client = createExpertApi({ getUri: () => 'https://expert.test' } as unknown as AxiosInstance)

    await client.streamMessage('conversation-1', 'Вопрос', 'request-1', [], {
      onRun: () => undefined,
      onDelta: (_runId, _seq, text) => received.push(`delta:${text}`),
      onDone: () => received.push('done'),
      onCancelled: () => undefined,
      onError: () => undefined,
    })

    expect(received).toEqual(['delta:Первая часть. ', 'delta:Вторая часть.', 'done'])
    vi.unstubAllGlobals()
  })

  it('ignores all events delivered after the first stream terminal event', async () => {
    vi.stubGlobal('document', { cookie: '' })
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response([
      'event: run\ndata: {"version":1,"run_id":"run-1","user_message":{"public_id":"u1","role":"user","content":"Вопрос","created_at":"2026-09-15T10:00:00Z"}}\n\n',
      'event: done\ndata: {"version":1}\n\n',
      'event: error\ndata: {"version":1,"code":"expert_stream_interrupted"}\n\n',
      'event: activity\ndata: {"version":1,"run_id":"run-1","seq":1,"activity_id":"a1","code":"generation.interrupted","status":"failed","category":"generation"}\n\n',
    ].join(''), { status: 200, headers: { 'Content-Type': 'text/event-stream' } })))
    const received: string[] = []
    const client = createExpertApi({ getUri: () => 'https://expert.test' } as unknown as AxiosInstance)

    await client.streamMessage('conversation-1', 'Вопрос', 'message-1', [], {
      onRun: () => undefined,
      onDelta: () => undefined,
      onActivity: () => received.push('activity'),
      onDone: () => received.push('done'),
      onCancelled: () => received.push('cancelled'),
      onError: () => received.push('error'),
    })

    expect(received).toEqual(['done'])
    vi.unstubAllGlobals()
  })
})
