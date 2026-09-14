import type { AxiosInstance } from 'axios'
import { describe, expect, it, vi } from 'vitest'
import { createExpertApi, isDemoProjectId, isExpertMaterialContextError, mapExpertApiError, mapExpertProject, mapFinding, mapMaterial, toProjectPayload } from './api'

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

    const conflict = mapExpertApiError({
      isAxiosError: true,
      response: {status:409, data:{code:'expert_request_conflict', message:'Snapshot изменён.'}},
    })

    expect(conflict).toMatchObject({status:409,code:'expert_request_conflict',message:'Snapshot изменён.'})
  })
})
