import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '../api.js'

export const useFilesStore = defineStore('files', () => {
  // State
  const folders = ref({})    // { folderName: file[] }
  const files = ref([])      // flat file[]
  const loading = ref(false)
  const error = ref(null)
  const activeFileId = ref(null)
  const sparklines = ref({}) // { fileId: number[] }

  // Derived
  const activeFile = computed(() =>
    files.value.find(f => f.id === activeFileId.value) ?? null
  )

  const allFiles = computed(() => {
    // Flatten folders + root files into one list, sorted by mtime desc
    const list = [...files.value]
    for (const folderFiles of Object.values(folders.value)) {
      list.push(...folderFiles)
    }
    // Deduplicate by id
    const seen = new Set()
    return list.filter(f => {
      if (seen.has(f.id)) return false
      seen.add(f.id)
      return true
    }).sort((a, b) => b.mtime - a.mtime)
  })

  // Actions
  async function loadFiles() {
    loading.value = true
    error.value = null
    try {
      const data = await api.listFiles()
      folders.value = data.folders ?? {}
      files.value = data.files ?? []
    } catch (e) {
      error.value = e.message
    } finally {
      loading.value = false
    }
  }

  function setActiveFile(fileId) {
    activeFileId.value = fileId
  }

  async function loadSparkline(fileId) {
    if (sparklines.value[fileId]) return
    try {
      const data = await api.getSparkline(fileId)
      sparklines.value[fileId] = data.series ?? []
    } catch {}
  }

  async function clearFile(fileId) {
    await api.clearFile(fileId)
    await loadFiles()
  }

  async function deleteFile(fileId) {
    await api.deleteFile(fileId)
    if (activeFileId.value === fileId) activeFileId.value = null
    await loadFiles()
  }

  async function downloadFile(fileId) {
    const { url } = await api.getDownloadUrl(fileId)
    window.location.href = url
  }

  return {
    folders, files, loading, error, activeFileId, activeFile, allFiles, sparklines,
    loadFiles, setActiveFile, loadSparkline, clearFile, deleteFile, downloadFile,
  }
})
