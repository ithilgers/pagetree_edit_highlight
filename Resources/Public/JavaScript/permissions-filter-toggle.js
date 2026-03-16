import Persistent from '@typo3/backend/storage/persistent.js';
import BrowserSession from '@typo3/backend/storage/browser-session.js';
import Notification from '@typo3/backend/notification.js';

const STORAGE_KEY = 'pageTree_permissionsFilterActive';
const SESSION_FLAG = 'pageTree_permissionsFilterSession';
const BANNER_ID = 'permissions-filter-banner';

class PermissionsFilterToggle {
    constructor() {
        this.initSessionState();
        this.waitForToolbar();
    }

    initSessionState() {
        if (BrowserSession.get(SESSION_FLAG)) {
            this.active = Persistent.get(STORAGE_KEY) === '1';
        } else {
            this.active = false;
            Persistent.set(STORAGE_KEY, '0');
            BrowserSession.set(SESSION_FLAG, '1');
        }
    }

    waitForToolbar() {
        const toolbar = document.querySelector('.svg-toolbar__submenu');
        if (toolbar) {
            this.insertButton(toolbar);
            return;
        }

        const observer = new MutationObserver(() => {
            const toolbar = document.querySelector('.svg-toolbar__submenu');
            if (toolbar) {
                clearTimeout(timeout);
                observer.disconnect();
                this.insertButton(toolbar);
            }
        });
        observer.observe(document.body, { childList: true, subtree: true });

        const timeout = setTimeout(() => {
            observer.disconnect();
        }, 10000);
    }

    insertButton(toolbar) {
        const dropdownMenu = toolbar.querySelector('.dropdown-menu');
        if (!dropdownMenu) {
            return;
        }

        const li = document.createElement('li');
        li.innerHTML = `
            <button class="dropdown-item" id="permissions-filter-toggle" type="button">
                <span class="dropdown-item-columns">
                    <span class="dropdown-item-column dropdown-item-column-icon" aria-hidden="true">
                        <typo3-backend-icon identifier="actions-filter" size="small"></typo3-backend-icon>
                    </span>
                    <span class="dropdown-item-column dropdown-item-column-title">
                        ${TYPO3.lang['filter.toggle.label'] || 'Editable pages only'}
                    </span>
                </span>
            </button>
        `;

        const button = li.querySelector('button');
        this.applyStyle(button);

        button.addEventListener('click', async () => {
            this.active = !this.active;
            this.applyStyle(button);
            this.updateBanner();
            await Persistent.set(STORAGE_KEY, this.active ? '1' : '0');
            BrowserSession.set(SESSION_FLAG, '1');
            if (this.active) {
                Notification.info(
                    TYPO3.lang['filter.notification.title'] || 'Page tree filter',
                    TYPO3.lang['filter.notification.message'] || 'Only pages you have editing permissions for are displayed.',
                    5
                );
            }
            document.dispatchEvent(new CustomEvent('typo3:pagetree:refresh'));
        });

        dropdownMenu.appendChild(li);
        this.updateBanner();
    }

    updateBanner() {
        const existing = document.getElementById(BANNER_ID);
        if (this.active && !existing) {
            const treeContainer = document.querySelector('#typo3-pagetree-treeContainer');
            if (!treeContainer) {
                return;
            }
            const banner = document.createElement('div');
            banner.id = BANNER_ID;
            banner.className = 'node-mount-point';
            banner.innerHTML = `
                <div class="node-mount-point__icon">
                    <typo3-backend-icon identifier="actions-filter" size="small"></typo3-backend-icon>
                </div>
                <div class="node-mount-point__text">${TYPO3.lang['filter.toggle.label'] || 'Editable pages only'}</div>
                <div class="node-mount-point__icon mountpoint-close" title="${TYPO3.lang['filter.banner.close'] || 'Deactivate filter'}">
                    <typo3-backend-icon identifier="actions-close" size="small"></typo3-backend-icon>
                </div>
            `;
            banner.querySelector('.mountpoint-close').addEventListener('click', async () => {
                this.active = false;
                const toggleButton = document.getElementById('permissions-filter-toggle');
                if (toggleButton) {
                    this.applyStyle(toggleButton);
                }
                this.updateBanner();
                await Persistent.set(STORAGE_KEY, '0');
                document.dispatchEvent(new CustomEvent('typo3:pagetree:refresh'));
            });
            treeContainer.prepend(banner);
        } else if (!this.active && existing) {
            existing.remove();
        }
    }

    applyStyle(button) {
        if (this.active) {
            button.classList.add('active');
        } else {
            button.classList.remove('active');
        }
    }
}

export default new PermissionsFilterToggle();
