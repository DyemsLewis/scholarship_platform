(function (window) {
    function fallbackAlert(config) {
        const sections = Array.isArray(config.sections) ? config.sections : [];
        const lines = sections.map((section) => `${section.heading}: ${section.body}`);
        window.alert([config.title, config.intro].concat(lines).join('\n\n'));
    }

    function buildModalHtml(config) {
        const sections = Array.isArray(config.sections) ? config.sections : [];
        const sectionsHtml = sections.map((section, index) => `
            <article class="policy-modal-section">
                <div class="policy-modal-section-number">${index + 1}</div>
                <div class="policy-modal-section-copy">
                    <h4>${section.heading}</h4>
                    <p>${section.body}</p>
                </div>
            </article>
        `).join('');

        return `
            <div class="policy-modal policy-modal--${config.variant || 'terms'}">
                <div class="policy-modal-hero">
                    <div class="policy-modal-icon">
                        <i class="${config.icon || 'fas fa-file-contract'}"></i>
                    </div>
                    <div class="policy-modal-hero-copy">
                        <span class="policy-modal-eyebrow">${config.eyebrow || 'Policy Details'}</span>
                        <h3>${config.title || 'Terms and Conditions'}</h3>
                        <p>${config.intro || ''}</p>
                    </div>
                </div>
                <div class="policy-modal-grid">
                    ${sectionsHtml}
                </div>
            </div>
        `;
    }

    function openModal(config) {
        if (typeof window.Swal === 'undefined' || !window.Swal || typeof window.Swal.fire !== 'function') {
            fallbackAlert(config);
            return Promise.resolve();
        }

        return window.Swal.fire({
            html: buildModalHtml(config),
            width: 760,
            padding: '1.2rem',
            showCloseButton: false,
            confirmButtonText: 'Close',
            buttonsStyling: false,
            customClass: {
                popup: 'policy-modal-popup',
                htmlContainer: 'policy-modal-html',
                confirmButton: 'policy-modal-confirm'
            }
        });
    }

    const POLICY_MAP = {
        studentTerms: {
            variant: 'terms',
            icon: 'fas fa-file-contract',
            eyebrow: 'Student Agreement',
            title: 'Terms and Conditions',
            intro: 'Creating an account means the information you submit should be truthful, complete, and ready for scholarship review.',
            sections: [
                {
                    heading: 'Accurate application details',
                    body: 'You confirm that the profile details, grades, and academic information you submit are complete and accurate at the time of registration.'
                },
                {
                    heading: 'Valid supporting documents',
                    body: 'Any file you upload must be clear, readable, and authentic. The platform may flag or reject documents that appear incomplete or inconsistent.'
                },
                {
                    heading: 'Fair scholarship review',
                    body: 'Scholarship matching and decisions depend on provider requirements, document verification, and the review process of the awarding organization.'
                },
                {
                    heading: 'Account responsibility',
                    body: 'You are responsible for keeping your login details secure and for updating your student profile when your information changes.'
                }
            ]
        },
        studentPrivacy: {
            variant: 'privacy',
            icon: 'fas fa-user-shield',
            eyebrow: 'Data Privacy Notice',
            title: 'Privacy Policy',
            intro: 'Your personal information is handled for scholarship matching, application processing, and account-related communication only.',
            sections: [
                {
                    heading: 'Information we collect',
                    body: 'The system collects account details, contact information, academic background, uploaded documents, and location details you provide during registration.'
                },
                {
                    heading: 'How data is used',
                    body: 'Your information is used to match you with scholarships, support eligibility review, and keep your application records accurate.'
                },
                {
                    heading: 'How data is protected',
                    body: 'The platform applies account verification, document review, and storage controls designed to help protect your submitted records.'
                },
                {
                    heading: 'Your control over your data',
                    body: 'You may request corrections to inaccurate profile information and review the data you have submitted through your account records.'
                }
            ]
        },
        providerTerms: {
            variant: 'terms',
            icon: 'fas fa-building-columns',
            eyebrow: 'Provider Review Terms',
            title: 'Provider Terms and Conditions',
            intro: 'Submitting a provider account means your organization details may be reviewed before access is granted.',
            sections: [
                {
                    heading: 'Accurate organization details',
                    body: 'You confirm that the organization name, contact person, location, and profile details entered are accurate and belong to your institution.'
                },
                {
                    heading: 'Review and approval',
                    body: 'Provider accounts are subject to review. Approval may depend on verification files, organization legitimacy, and completeness of submitted details.'
                },
                {
                    heading: 'Responsible scholarship posting',
                    body: 'Approved providers are expected to post clear scholarship requirements, keep schedules updated, and avoid misleading information.'
                },
                {
                    heading: 'Account accountability',
                    body: 'The organization is responsible for protecting login credentials and for promptly updating any provider information that changes.'
                }
            ]
        },
        providerPrivacy: {
            variant: 'privacy',
            icon: 'fas fa-user-lock',
            eyebrow: 'Provider Privacy Notice',
            title: 'Provider Privacy Policy',
            intro: 'Organization and contact information is stored to support provider verification, scholarship management, and account communication.',
            sections: [
                {
                    heading: 'Provider information collected',
                    body: 'The system stores organization details, contact person information, login email, address, and any verification file you choose to submit.'
                },
                {
                    heading: 'Why it is used',
                    body: 'These records help the platform review provider legitimacy, manage scholarship postings, and communicate account updates or approval results.'
                },
                {
                    heading: 'Data review and protection',
                    body: 'Submitted provider data is reviewed by authorized staff and handled using storage and access controls available within the platform.'
                },
                {
                    heading: 'Updating provider records',
                    body: 'Providers should keep organization details current so scholarship listings, address information, and official communications remain accurate.'
                }
            ]
        },
        scholarshipTerms: {
            variant: 'terms',
            icon: 'fas fa-scroll',
            eyebrow: 'Application Submission Terms',
            title: 'Scholarship Terms and Verification Policy',
            intro: 'Submitting this application means your information and documents may be reviewed, verified, and assessed against the scholarship rules of the provider.',
            sections: [
                {
                    heading: 'Complete and truthful submission',
                    body: 'You confirm that the personal information, grades, and supporting details in this application are true and complete.'
                },
                {
                    heading: 'Document verification',
                    body: 'Uploaded records may be checked by reviewers or scholarship providers, and pending or rejected documents can block submission or review.'
                },
                {
                    heading: 'Eligibility and assessment',
                    body: 'Application progress depends on scholarship requirements, document review, and any exam, interview, or additional assessment defined by the provider.'
                },
                {
                    heading: 'Final review outcome',
                    body: 'Submitting the application does not guarantee approval. Final decisions remain with the scholarship provider or authorized reviewing team.'
                }
            ]
        },
        scholarshipPrivacy: {
            variant: 'privacy',
            icon: 'fas fa-shield-halved',
            eyebrow: 'Application Privacy Notice',
            title: 'Application Privacy Policy',
            intro: 'Your submitted profile, documents, and application details are used to process the scholarship you are applying for and to support review decisions.',
            sections: [
                {
                    heading: 'Application records collected',
                    body: 'The application uses your student profile, uploaded requirements, scholarship answers, and matching data generated during the application flow.'
                },
                {
                    heading: 'How the records are used',
                    body: 'These details help reviewers validate eligibility, assess submitted documents, and make scholarship-related decisions.'
                },
                {
                    heading: 'Who may review them',
                    body: 'Authorized reviewers and approved scholarship providers may view only the records needed to review your application.'
                },
                {
                    heading: 'Why accuracy matters',
                    body: 'Incorrect or outdated records can delay review, affect matching results, or require resubmission when the provider checks your application.'
                }
            ]
        }
    };

    window.PolicyModal = {
        open: openModal,
        show(key) {
            if (!POLICY_MAP[key]) {
                return Promise.resolve();
            }

            return openModal(POLICY_MAP[key]);
        },
        openStudentTerms() {
            return openModal(POLICY_MAP.studentTerms);
        },
        openStudentPrivacy() {
            return openModal(POLICY_MAP.studentPrivacy);
        },
        openProviderTerms() {
            return openModal(POLICY_MAP.providerTerms);
        },
        openProviderPrivacy() {
            return openModal(POLICY_MAP.providerPrivacy);
        },
        openScholarshipTerms() {
            return openModal(POLICY_MAP.scholarshipTerms);
        },
        openScholarshipPrivacy() {
            return openModal(POLICY_MAP.scholarshipPrivacy);
        }
    };
})(window);
