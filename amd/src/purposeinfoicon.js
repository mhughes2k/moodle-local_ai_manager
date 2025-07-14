// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Module showing a modal with the purpose information.
 *
 * @module     local_ai_manager/purposeinfoicon
 * @copyright  2025 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Modal from 'core/modal';
import {getPurposesUsageInfo} from 'local_ai_manager/config';

export const init = async(purposeInfoIconSelector) => {
    const purposeInfoIcon = document.querySelector(purposeInfoIconSelector);
    const purposesUsageInfo = await getPurposesUsageInfo();
    const purposeTemplateContext =
        purposesUsageInfo.purposes.find(purposeObject => purposeObject.purposename === purposeInfoIcon.dataset.purposename);

    purposeInfoIcon.addEventListener('click', async() => {
        const instanceAddModal = await Modal.create({
            template: 'local_ai_manager/purposeusageinfomodal',
            large: true,
            templateContext: purposeTemplateContext
        });
        await instanceAddModal.show();
    });
};
