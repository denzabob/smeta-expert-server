import type { AxiosInstance } from 'axios'
import { describe, expect, it, vi } from 'vitest'
import { createExpertApi, isDemoProjectId, mapExpertApiError, mapExpertProject, mapFinding, mapMaterial, toProjectPayload } from './api'

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
  it('provides stable fallback error mapping', () => { expect(mapExpertApiError(new Error('x'))).toEqual({message:'Не удалось выполнить запрос.',validationErrors:{}}) })
  it('maps private material metadata without exposing a storage path', () => {
    const material=mapMaterial({public_id:'m1',original_name:'evidence.pdf',mime_type:'application/pdf',extension:'pdf',size:2048,category:'document',status:'uploaded',created_at:'2026-09-12T10:00:00Z'})
    expect(material).toMatchObject({id:'m1',name:'evidence.pdf',kind:'document',size:'2.0 КБ',status:'Загружен'})
    expect(material).not.toHaveProperty('storagePath')
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
})
