@props([
  'model',
  'mode' => 'multi',
  'label' => 'Dateien hier ablegen oder klicken.',
  'acceptedFiles' => null,
  'maxFiles' => null,
  'maxFilesize' => null,
])

@php
  $isSingle      = ($mode === 'single' || $mode === true || $mode === 1 || $mode === '1');
  $dzMaxFiles    = $isSingle ? 1 : ($maxFiles ?? 20);
  $dzMaxFilesize = $maxFilesize ?? 15;
  $dzAccepted    = $acceptedFiles; // z. B. ".pdf,.png,.jpg"
@endphp

<div
  x-data="{
    dz: null,
    active: false, // Upload-Scope nur für DIESES Input
    single: @js($isSingle),
    opts: {
      maxFiles: @js($dzMaxFiles),
      maxFilesize: @js($dzMaxFilesize),
      acceptedFiles: @js($dzAccepted),
    },

    init() {
      console.log('Dropzone init', @js($model), this.single, this.opts);
      // optionales externes Reset (z. B. nach Server-Save)
      window.addEventListener('filepool:saved', (e) => {
        if (e?.detail?.model === @js($model)) this.resetDZ();
      });

      // multiple-Attribut am Input setzen + active-Scope setzen, wenn dieses Input benutzt wird
      this.$nextTick(() => {
        const input = this.$refs.fileInput;
        if (!input) return;
        if (this.single) input.removeAttribute('multiple'); else input.setAttribute('multiple', 'multiple');
        input.addEventListener('change', () => { this.active = true; }, { once:false });
      });

      this.$nextTick(() => this.mountDZ());
    },

    mountDZ() {
      if (this.dz) return;
      if (!window.Dropzone) { console.error('Dropzone fehlt im Layout'); return; }
      Dropzone.autoDiscover = false;

      const el = this.$refs.dzForm;
      const input = this.$refs.fileInput; // NICHT in wire:ignore
      if (!el || !input) return;

      const previews = el.querySelector('.dz-previews') || el;


      this.dz = new Dropzone(el, {
        url: '#', // nur UI – Upload macht Livewire
        autoProcessQueue: false,
        clickable: el,
        previewsContainer: previews,
        addRemoveLinks: true,
        maxFiles: this.opts.maxFiles,
        maxFilesize: this.opts.maxFilesize,
        acceptedFiles: this.opts.acceptedFiles ?? undefined,
        chunking: true,
        chunkSize: 10000000, // rein visuell
        dictRemoveFile: 'Datei löschen',
        dictMaxFilesExceeded: 'Maximale Anzahl an Dateien erreicht.',
        dictFileTooBig: 'Datei zu groß ',
        dictInvalidFileType: 'Dieser Dateityp ist nicht erlaubt.',
        dictResponseError: 'Serverfehler',
        dictCancelUpload: 'Upload abbrechen',
        dictUploadCanceled: 'Upload abgebrochen.',
        dictCancelUploadConfirmation: 'Upload wirklich abbrechen?',
        dictRemoveFileConfirmation: 'Datei wirklich löschen?',
      });



      // SINGLE: neue Datei ersetzt alte
      this.dz.on('maxfilesexceeded', (file) => {
        if (!this.single) return;
        this.dz.removeAllFiles();
        this.dz.addFile(file);
      });

      // Datei hinzugefügt → verstecktes Input aktualisieren (Livewire-Upload anstoßen)
      this.dz.on('addedfile', (file) => {
        const dt = new DataTransfer();
        if (this.single) {
          dt.items.add(file);
        } else {
          for (const f of input.files) dt.items.add(f);
          dt.items.add(file);
        }
        input.files = dt.files;
        input.dispatchEvent(new Event('change', { bubbles: true }));

        // Sofort visuell als 'processing' markieren (bis Livewire Progress liefert)
        this.dz.emit('processing', file);
        this.dz.emit('uploadprogress', file, 0, 0);
      });

      // Datei entfernt → auch aus dem Input entfernen
      this.dz.on('removedfile', (file) => {
        const dt = new DataTransfer();
        if (!this.single) {
          for (const f of input.files) {
            const same = f.name === file.name && f.size === file.size && f.lastModified === file.lastModified;
            if (!same) dt.items.add(f);
          }
        } // bei single leeren wir einfach
        input.files = dt.files;
        input.dispatchEvent(new Event('change', { bubbles: true }));
      });

      // ---- Livewire-Upload-Events → auf Dropzone spiegeln ----
      const markAllAsProcessing = () => {
        if (!this.active) return;
        this.dz.files.forEach(f => {
          if (f.status !== Dropzone.SUCCESS && f.status !== Dropzone.ERROR) {
            this.dz.emit('processing', f);
            this.dz.emit('uploadprogress', f, 0, 0);
          }
        });
      };

      const updateAllProgress = (percent) => {
        if (!this.active) return;
        this.dz.files.forEach(f => {
          if (f.status === Dropzone.UPLOADING || f.status === Dropzone.PROCESSING || f.status === Dropzone.QUEUED) {
            const bytesSent = Math.round((percent / 100) * (f.size || 0));
            this.dz.emit('uploadprogress', f, percent, bytesSent);
          }
        });
      };

      const finishAll = (ok = true) => {
        if (!this.active) return;
        this.dz.files.forEach(f => {
          if (ok) this.dz.emit('success', f, {});
          else    this.dz.emit('error',   f, 'Upload fehlgeschlagen');
          this.dz.emit('complete', f);
          f.previewElement?.classList.toggle('dz-success', ok);
          f.previewElement?.classList.toggle('dz-error', !ok);
        });
        // Entfernen-Link nach Erfolg ausblenden? (optional)
        // this.dz.files.forEach(f => f.previewElement?.querySelector('.dz-remove')?.classList.add('hidden'));
        this.active = false; // Scope zurücksetzen
      };

      // Globale Livewire-Browser-Events hooken (lokal filtern via this.active)
      document.addEventListener('livewire-upload-start',   () => markAllAsProcessing());
      document.addEventListener('livewire-upload-progress',e => updateAllProgress(e.detail.progress));
      document.addEventListener('livewire-upload-error',   () => finishAll(false));
      document.addEventListener('livewire-upload-finish',  () => finishAll(true));
    },

    resetDZ() {
      if (this.dz) this.dz.removeAllFiles(true);
      const input = this.$refs.fileInput;
      if (!input) return;
      const empty = new DataTransfer();
      input.files = empty.files;
      input.dispatchEvent(new Event('change', { bubbles: true }));
    },
  }"
  x-init="init()"
>
  <!-- Dropzone-UI (vor Livewire geschützt) -->
  <div x-ref="dzForm"
        class="dropzone pointer-events-auto min-h-[140px] rounded-xl border-2 border-dashed border-gray-300 bg-gray-50"
        wire:ignore>
    <div class="dz-message needsclick">
      <h5 class="text-gray-600 dark:text-gray-100">{{ $label }}</h5>
      @if($isSingle)
        <p class="text-xs text-gray-400">Max. 1 Datei</p>
      @endif
    </div>
    <div class="dz-previews flex items-center flex-wrap justify-around gap-2"></div>
</div>

  <!-- Livewire-Input (Modus-abhängig multiple) -->
  <input
    x-ref="fileInput"
    type="file"
    @if(!$isSingle) multiple @endif
    class="hidden"
    wire:model="{{ $model }}"
  >

  @error($model)
    <span class="text-sm text-red-600">{{ $message }}</span>
  @enderror
</div>
