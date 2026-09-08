export function isCurrentPreviewIdentity(
  previewPublicId: string,
  sourceFilePublicId: string,
  selectedPreviewPublicId: string | null | undefined,
  selectedSourceFilePublicId: string | null | undefined,
): boolean {
  return previewPublicId === selectedPreviewPublicId && sourceFilePublicId === selectedSourceFilePublicId
}
