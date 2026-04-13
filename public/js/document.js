/**
 * document.js - Document Management Module
 * Handles document upload, deletion, and modal interactions
 */

class DocumentManager {
    constructor() {
        this.elements = {
            uploadModal: null,
            fileInput: null,
            uploadArea: null,
            fileNameDisplay: null,
            documentType: null,
            modalTitle: null,
            requirementsList: null,
            uploadBtn: null,
            uploadModeNote: null,
            documentsGrid: null,
            documentCards: [],
            documentSearch: null,
            documentStatusFilter: null,
            documentFilterReset: null,
            documentFilterSummary: null,
            documentFilterEmpty: null,
            filePreviewModal: null,
            filePreviewBody: null,
            filePreviewName: null,
            filePreviewClose: null,
            filePreviewDone: null
        };
        this.init();
    }

    init() {
        this.cacheElements();
        this.bindEvents();
        this.setupModalCloseHandlers();
    }

    cacheElements() {
        this.elements.uploadModal = document.getElementById('uploadModal');
        this.elements.fileInput = document.getElementById('fileInput');
        this.elements.uploadArea = document.getElementById('uploadArea');
        this.elements.fileNameDisplay = document.getElementById('fileName');
        this.elements.documentType = document.getElementById('documentType');
        this.elements.modalTitle = document.getElementById('modalTitle');
        this.elements.requirementsList = document.getElementById('requirementsList');
        this.elements.uploadBtn = document.getElementById('uploadBtn');
        this.elements.uploadModeNote = document.getElementById('uploadModeNote');
        this.elements.documentsGrid = document.getElementById('documentsGrid');
        this.elements.documentCards = Array.from(document.querySelectorAll('.doc-card'));
        this.elements.documentSearch = document.getElementById('documentSearch');
        this.elements.documentStatusFilter = document.getElementById('documentStatusFilter');
        this.elements.documentFilterReset = document.getElementById('documentFilterReset');
        this.elements.documentFilterSummary = document.getElementById('documentFilterSummary');
        this.elements.documentFilterEmpty = document.getElementById('documentFilterEmpty');
        this.elements.filePreviewModal = document.getElementById('filePreviewModal');
        this.elements.filePreviewBody = document.getElementById('filePreviewBody');
        this.elements.filePreviewName = document.getElementById('filePreviewName');
        this.elements.filePreviewClose = document.getElementById('filePreviewClose');
        this.elements.filePreviewDone = document.getElementById('filePreviewDone');
    }

    bindEvents() {
        const uploadForm = document.getElementById('uploadForm');
        if (uploadForm) {
            uploadForm.addEventListener('submit', (e) => this.handleUpload(e));
        }

        if (this.elements.uploadArea && this.elements.fileInput) {
            this.elements.uploadArea.addEventListener('dragover', (e) => this.handleDragOver(e));
            this.elements.uploadArea.addEventListener('dragleave', (e) => this.handleDragLeave(e));
            this.elements.uploadArea.addEventListener('drop', (e) => this.handleDrop(e));
            this.elements.fileInput.addEventListener('change', () => this.updateFileName());
        }

        if (this.elements.uploadArea) {
            this.elements.uploadArea.addEventListener('click', () => {
                if (this.elements.fileInput) this.elements.fileInput.click();
            });
        }

        if (this.elements.documentSearch) {
            this.elements.documentSearch.addEventListener('input', () => this.applyDocumentFilters());
            this.elements.documentSearch.addEventListener('search', () => this.applyDocumentFilters());
            this.elements.documentSearch.addEventListener('change', () => this.applyDocumentFilters());
            this.elements.documentSearch.addEventListener('keyup', () => this.applyDocumentFilters());
        }

        if (this.elements.documentStatusFilter) {
            this.elements.documentStatusFilter.addEventListener('change', () => this.applyDocumentFilters());
        }

        if (this.elements.documentFilterReset) {
            this.elements.documentFilterReset.addEventListener('click', () => this.resetDocumentFilters());
        }

        document.addEventListener('click', (event) => {
            const previewTrigger = event.target.closest('[data-file-preview-trigger]');
            if (!previewTrigger) {
                return;
            }

            event.preventDefault();
            this.openFilePreview(
                previewTrigger.dataset.fileUrl || '',
                previewTrigger.dataset.fileType || 'document',
                previewTrigger.dataset.fileName || 'Uploaded document'
            );
        });

        if (this.elements.filePreviewClose) {
            this.elements.filePreviewClose.addEventListener('click', () => this.closeFilePreview());
        }

        if (this.elements.filePreviewDone) {
            this.elements.filePreviewDone.addEventListener('click', () => this.closeFilePreview());
        }

        this.applyDocumentFilters();
    }

    setupModalCloseHandlers() {
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && this.elements.uploadModal?.classList.contains('active')) {
                this.closeUploadModal();
            }
            if (e.key === 'Escape' && this.elements.filePreviewModal && !this.elements.filePreviewModal.hasAttribute('hidden')) {
                this.closeFilePreview();
            }
        });

        window.addEventListener('click', (event) => {
            if (event.target === this.elements.uploadModal) {
                this.closeUploadModal();
            }
            if (event.target === this.elements.filePreviewModal) {
                this.closeFilePreview();
            }
        });
    }

    openUploadModal(docType, docName, mode = 'upload') {
        const isUpdateMode = mode === 'update';

        if (this.elements.modalTitle) {
            this.elements.modalTitle.textContent = `${isUpdateMode ? 'Update' : 'Upload'} ${docName}`;
        }
        if (this.elements.documentType) {
            this.elements.documentType.value = docType;
        }
        if (this.elements.uploadBtn) {
            this.elements.uploadBtn.innerHTML = isUpdateMode
                ? '<i class="fas fa-rotate"></i> Update Document'
                : '<i class="fas fa-upload"></i> Upload Document';
        }
        if (this.elements.uploadModeNote) {
            if (isUpdateMode) {
                this.elements.uploadModeNote.innerHTML = '<i class="fas fa-circle-info"></i> Uploading a new file here will replace your current document and send it back for review.';
                this.elements.uploadModeNote.hidden = false;
            } else {
                this.elements.uploadModeNote.hidden = true;
                this.elements.uploadModeNote.textContent = '';
            }
        }
        if (this.elements.fileNameDisplay) {
            this.elements.fileNameDisplay.style.display = 'none';
            this.elements.fileNameDisplay.textContent = '';
        }
        if (this.elements.fileInput) {
            this.elements.fileInput.value = '';
        }

        this.updateRequirements(docType);

        if (this.elements.uploadModal) {
            this.elements.uploadModal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
    }

    closeUploadModal() {
        if (this.elements.uploadModal) {
            this.elements.uploadModal.classList.remove('active');
            document.body.style.overflow = 'auto';
        }

        if (this.elements.uploadModeNote) {
            this.elements.uploadModeNote.hidden = true;
            this.elements.uploadModeNote.textContent = '';
        }

        if (this.elements.uploadBtn) {
            this.elements.uploadBtn.innerHTML = '<i class="fas fa-upload"></i> Upload Document';
            this.elements.uploadBtn.disabled = false;
        }
    }

    openFilePreview(fileUrl, fileType, fileName) {
        if (!this.elements.filePreviewModal || !this.elements.filePreviewBody) {
            if (fileUrl) {
                window.open(fileUrl, '_blank', 'noopener');
            }
            return;
        }

        const safeUrl = (fileUrl || '').trim();
        if (!safeUrl) {
            this.showNotification('File unavailable', 'This uploaded file could not be found.', 'warning');
            return;
        }

        if (this.elements.filePreviewName) {
            this.elements.filePreviewName.textContent = fileName || 'Uploaded document';
        }

        let previewMarkup = '';
        if (fileType === 'image') {
            previewMarkup = `
                <div class="file-preview-frame image-preview-frame">
                    <img src="${this.escapeHtmlAttribute(safeUrl)}" alt="${this.escapeHtmlAttribute(fileName || 'Uploaded document')}" class="file-preview-image">
                </div>
            `;
        } else if (fileType === 'pdf') {
            previewMarkup = `
                <div class="file-preview-frame pdf-preview-frame">
                    <iframe src="${this.escapeHtmlAttribute(safeUrl)}" class="file-preview-iframe" title="${this.escapeHtmlAttribute(fileName || 'Uploaded PDF')}"></iframe>
                </div>
            `;
        } else {
            previewMarkup = `
                <div class="file-preview-fallback">
                    <i class="fas fa-file-lines"></i>
                    <h4>Preview unavailable for this file type</h4>
                    <p>This file type cannot be previewed inside the modal.</p>
                </div>
            `;
        }

        this.elements.filePreviewBody.innerHTML = previewMarkup;
        this.elements.filePreviewModal.hidden = false;
        this.elements.filePreviewModal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    closeFilePreview() {
        if (!this.elements.filePreviewModal || !this.elements.filePreviewBody) {
            return;
        }

        this.elements.filePreviewModal.classList.remove('active');
        this.elements.filePreviewModal.hidden = true;
        this.elements.filePreviewBody.innerHTML = `
            <div class="file-preview-fallback">
                <i class="fas fa-file-circle-question"></i>
                <h4>No file selected</h4>
                <p>Select a document to preview it here.</p>
            </div>
        `;

        if (!this.elements.uploadModal?.classList.contains('active')) {
            document.body.style.overflow = 'auto';
        }
    }

    resetDocumentFilters() {
        if (this.elements.documentSearch) {
            this.elements.documentSearch.value = '';
        }

        if (this.elements.documentStatusFilter) {
            this.elements.documentStatusFilter.value = 'all';
        }

        this.applyDocumentFilters();
    }

    normalizeFilterText(value) {
        return String(value || '')
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
    }

    getDocumentCards() {
        this.elements.documentCards = Array.from(document.querySelectorAll('.doc-card'));
        return this.elements.documentCards;
    }

    getDocumentSearchHaystack(card) {
        const datasetText = card.dataset.documentSearchIndex || [
            card.dataset.documentName || '',
            card.dataset.documentType || '',
            card.dataset.documentDescription || '',
            card.dataset.documentFile || ''
        ].join(' ');

        return this.normalizeFilterText(datasetText);
    }

    matchesDocumentSearch(card, searchValue) {
        const normalizedSearch = this.normalizeFilterText(searchValue);
        if (normalizedSearch === '') {
            return true;
        }

        const searchTokens = normalizedSearch.split(' ').filter(Boolean);
        if (!searchTokens.length) {
            return true;
        }

        const haystack = this.getDocumentSearchHaystack(card);
        return searchTokens.every((token) => haystack.includes(token));
    }

    matchesDocumentStatus(card, statusValue) {
        const normalizedStatus = this.normalizeFilterText(statusValue);
        const documentStatus = this.normalizeFilterText(card.dataset.documentStatus || 'missing');

        if (normalizedStatus === '' || normalizedStatus === 'all') {
            return true;
        }

        if (normalizedStatus === 'uploaded') {
            return documentStatus !== 'missing';
        }

        if (normalizedStatus === 'rejected') {
            return documentStatus === 'rejected';
        }

        return documentStatus === normalizedStatus;
    }

    applyDocumentFilters() {
        const cards = this.getDocumentCards();
        if (!cards.length) {
            return;
        }

        const searchValue = this.elements.documentSearch?.value || '';
        const statusValue = this.elements.documentStatusFilter?.value || 'all';
        const totalCards = cards.length;
        let visibleCount = 0;

        cards.forEach((card) => {
            const shouldShow = this.matchesDocumentSearch(card, searchValue)
                && this.matchesDocumentStatus(card, statusValue);
            card.dataset.filterVisible = shouldShow ? 'true' : 'false';

            if (shouldShow) {
                visibleCount += 1;
            }
        });

        if (this.elements.documentFilterSummary) {
            this.elements.documentFilterSummary.textContent = visibleCount === totalCards
                ? `Showing all ${totalCards} document${totalCards === 1 ? '' : 's'}.`
                : `Showing ${visibleCount} of ${totalCards} document${totalCards === 1 ? '' : 's'}.`;
        }

        if (this.elements.documentFilterEmpty) {
            this.elements.documentFilterEmpty.hidden = visibleCount !== 0;
        }

        if (window.CardPagination && typeof window.CardPagination.refresh === 'function' && this.elements.documentsGrid) {
            window.CardPagination.refresh(this.elements.documentsGrid.id, true);
        } else {
            cards.forEach((card) => {
                card.style.display = card.dataset.filterVisible === 'false' ? 'none' : '';
            });
        }
    }

    updateRequirements(docType) {
        let requirements = [];
        
        const requirementsMap = {
            'id': ['Valid government-issued ID', 'ID must not be expired', 'Photo and details must be clear', 'Both sides if applicable'],
            'birth_certificate': ['PSA or NSO issued birth certificate', 'Document must be complete and clear', 'No alterations or damage', 'Late registered? Include additional docs'],
            'grades': ['Official transcript or grade slip', 'Must show all subjects and grades', 'School seal and signature visible', 'Recent grades (last semester)'],
            'form_138': ['Official senior high school report card (Form 137/138)', 'Student name and school year must be visible', 'Grades must be readable on every page', 'Upload the clearest copy available'],
            'good_moral': ['Issued by school guidance office', 'Must include date of issue', 'Valid for current school year', 'School seal and signature'],
            'enrollment': ['Official enrollment certificate', 'Current school year', 'School seal and registrar signature', 'Complete student details'],
            'income_tax': ['Latest ITR from BIR', 'Must show parent/guardian name', 'Complete with tax details', 'Official BIR stamp'],
            'citizenship_proof': ['Birth certificate, passport, or residency document', 'Name and citizenship or residency details must be visible', 'Document must be clear and complete', 'Upload the most recent official copy available'],
            'income_proof': ['Certificate of indigency, payslip, ITR, or income certification', 'Guardian or household details must be visible', 'Document should clearly support the income bracket stated', 'Upload a complete official copy'],
            'special_category_proof': ['Upload the document that supports the selected category', 'Examples: PWD ID, solo parent ID, 4Ps proof, OFW proof, or IP certification', 'Name and category details must be readable', 'Upload a clear and complete copy']
        };

        requirements = requirementsMap[docType] || ['File must be clear and readable', 'All information must be visible', 'No alterations or edits', 'Valid and not expired'];

        if (this.elements.requirementsList) {
            this.elements.requirementsList.innerHTML = requirements.map(req => 
                `<li><i class="fas fa-check"></i> ${req}</li>`
            ).join('');
        }
    }

    handleDragOver(e) {
        e.preventDefault();
        if (this.elements.uploadArea) {
            this.elements.uploadArea.style.borderColor = 'var(--primary)';
            this.elements.uploadArea.style.backgroundColor = 'rgba(44, 90, 160, 0.02)';
        }
    }

    handleDragLeave(e) {
        e.preventDefault();
        if (this.elements.uploadArea) {
            this.elements.uploadArea.style.borderColor = '';
            this.elements.uploadArea.style.backgroundColor = '';
        }
    }

    handleDrop(e) {
        e.preventDefault();
        if (this.elements.uploadArea) {
            this.elements.uploadArea.style.borderColor = '';
            this.elements.uploadArea.style.backgroundColor = '';
        }

        if (e.dataTransfer.files.length && this.elements.fileInput) {
            this.elements.fileInput.files = e.dataTransfer.files;
            this.updateFileName();
        }
    }

    updateFileName() {
        if (!this.elements.fileInput || !this.elements.fileNameDisplay) return;

        if (this.elements.fileInput.files && this.elements.fileInput.files[0]) {
            const file = this.elements.fileInput.files[0];
            const fileSize = (file.size / 1024 / 1024).toFixed(2);
            
            if (file.size > 5 * 1024 * 1024) {
                this.elements.fileNameDisplay.innerHTML = '<i class="fas fa-exclamation-circle"></i> File too large! Max 5MB.';
                this.elements.fileNameDisplay.style.color = '#e63946';
                this.elements.fileNameDisplay.style.display = 'block';
                this.elements.fileInput.value = '';
            } else {
                this.elements.fileNameDisplay.innerHTML = `<i class="fas fa-file"></i> Selected: ${file.name} (${fileSize} MB)`;
                this.elements.fileNameDisplay.style.color = 'var(--primary)';
                this.elements.fileNameDisplay.style.display = 'block';
            }
        }
    }

    async handleUpload(e) {
        e.preventDefault();
        
        if (!this.elements.fileInput || !this.elements.fileInput.files || !this.elements.fileInput.files[0]) {
            this.showNotification('No File Selected', 'Please select a file to upload.', 'warning');
            return;
        }
        
        const file = this.elements.fileInput.files[0];
        
        if (file.size > 5 * 1024 * 1024) {
            this.showNotification('File Too Large', 'Maximum file size is 5MB.', 'error');
            return;
        }
        
        const formData = new FormData(e.target);
        const originalText = this.elements.uploadBtn?.innerHTML || '';
        
        if (this.elements.uploadBtn) {
            this.elements.uploadBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
            this.elements.uploadBtn.disabled = true;
        }
        
        try {
            const response = await fetch('../app/Controllers/document_controller.php', {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                this.showNotification('Upload Successful!', data.message, 'success');
                this.closeUploadModal();
                setTimeout(() => location.reload(), 1500);
            } else {
                this.showNotification('Upload Failed', data.message, 'error');
                if (this.elements.uploadBtn) {
                    this.elements.uploadBtn.innerHTML = originalText;
                    this.elements.uploadBtn.disabled = false;
                }
            }
        } catch (error) {
            console.error('Upload error:', error);
            this.showNotification('Error', 'An error occurred during upload.', 'error');
            if (this.elements.uploadBtn) {
                this.elements.uploadBtn.innerHTML = originalText;
                this.elements.uploadBtn.disabled = false;
            }
        }
    }

    showNotification(title, text, type = 'info') {
        if (typeof Swal !== 'undefined') {
            const iconMap = { success: 'success', error: 'error', warning: 'warning', info: 'info' };
            Swal.fire({
                icon: iconMap[type] || 'info',
                title: title,
                text: text,
                timer: type === 'success' ? 2000 : undefined,
                showConfirmButton: type !== 'success',
                confirmButtonColor: '#2c5aa0'
            });
        } else {
            alert(`${title}: ${text}`);
        }
    }

    showConfirm(title, text, confirmText, cancelText) {
        if (typeof Swal !== 'undefined') {
            return Swal.fire({
                title: title,
                text: text,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#2c5aa0',
                cancelButtonColor: '#e63946',
                confirmButtonText: confirmText,
                cancelButtonText: cancelText
            });
        }
        return Promise.resolve({ isConfirmed: confirm(`${title}\n\n${text}`) });
    }

    escapeHtmlAttribute(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }
}

// Initialize when DOM is ready
let documentManager = null;

function ensureDocumentManager() {
    if (documentManager) {
        return documentManager;
    }

    try {
        documentManager = new DocumentManager();
    } catch (error) {
        console.error('Document manager initialization failed:', error);
        documentManager = null;
    }

    return documentManager;
}

document.addEventListener('DOMContentLoaded', function() {
    ensureDocumentManager();
});

// Global functions for inline onclick handlers
window.openUploadModal = function(docType, docName, mode) {
    const manager = ensureDocumentManager();
    if (manager) manager.openUploadModal(docType, docName, mode);
};

window.closeUploadModal = function() {
    const manager = ensureDocumentManager();
    if (manager) manager.closeUploadModal();
};

