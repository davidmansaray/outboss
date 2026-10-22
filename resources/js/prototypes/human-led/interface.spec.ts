import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import Workflow from './Workflow.vue';
function button(w: ReturnType<typeof mount>, name: string) {
    const found = w.findAll('button').find((b) => b.text() === name);

    if (!found) {
        throw new Error('Missing button: ' + name);
    }

    return found;
}
async function campaign(w: ReturnType<typeof mount>) {
    await button(w, 'Outreach').trigger('click');
    await button(w, 'New campaign').trigger('click');
    await w.find('input').setValue('Agency pilot');
    await w.findAll('select')[0].setValue('objective-1');
    await w.findAll('select')[1].setValue('offer-1');
}
describe('independent entity interface', () => {
    it('creates an Objective without creating a campaign and keeps it across navigation', async () => {
        const w = mount(Workflow);
        await button(w, 'New objective').trigger('click');
        await w.find('form input').setValue('Independent goal');
        await w.find('form').trigger('submit');
        expect(w.text()).toContain('Independent goal');
        expect(w.text()).toContain('No campaigns yet');
        await button(w, 'Offers').trigger('click');
        expect(w.text()).toContain('Pipeline clarity workshop');
        await button(w, 'Objectives').trigger('click');
        expect(w.text()).toContain('Independent goal');
    });
    it('creates an Offer inline, selects it, and makes it available independently', async () => {
        const w = mount(Workflow);
        await campaign(w);
        await button(w, 'Create new offer').trigger('click');
        await w.find('form input').setValue('Reusable audit');
        await w.find('form textarea').setValue('A written audit.');
        await w.find('form').trigger('submit');
        expect(w.text()).toContain('Reusable audit');
        await button(w, 'Offers').trigger('click');
        await button(w, 'Reusable audit').trigger('click');
        expect(w.text()).toContain('Agency pilot');
        await button(w, 'Outreach').trigger('click');
        await button(w, 'Agency pilot').trigger('click');
        expect(w.findAll('select')[1].element.value).not.toBe('offer-1');
    });
    it('runs a campaign through conversion and shows aggregate progress on its Objective', async () => {
        const w = mount(Workflow);
        await campaign(w);
        await button(w, 'Continue to Audience').trigger('click');
        await w.find('input[type=checkbox]').setValue(true);
        await button(w, 'Continue to Message').trigger('click');
        await w.find('input').setValue('A workshop for {{company}}');
        await w
            .find('textarea')
            .setValue('Hi {{first_name}}, would a workshop help?');
        await button(w, 'Continue to Review').trigger('click');
        await w.find('input[type=checkbox]').setValue(true);
        await button(w, 'Approve exact plan').trigger('click');
        await button(w, 'Launch demo campaign').trigger('click');
        await button(w, 'Simulate interested reply').trigger('click');
        await button(w, 'Send demo reply').trigger('click');
        await button(w, 'Simulate booking confirmation').trigger('click');
        await button(w, 'Record the outcome').trigger('click');
        await button(w, 'Record confirmed booking').trigger('click');
        await button(w, 'Objectives').trigger('click');
        await button(w, 'Book 6 qualified agency meetings').trigger('click');
        expect(w.text()).toContain('1 / 6');
        expect(w.text()).toContain('Agency pilot');
        await button(w, 'Create campaign').trigger('click');
        expect(w.findAll('select')[0].element.value).toBe('objective-1');
        await button(w, 'Objectives').trigger('click');
        await button(w, 'Book 6 qualified agency meetings').trigger('click');
        expect(w.text()).toContain('1 / 6');
        expect(w.text()).toContain('Untitled campaign');
    });
    it('retains campaign inputs while visiting independent entities', async () => {
        const w = mount(Workflow);
        await campaign(w);
        await button(w, 'Continue to Audience').trigger('click');
        await w.find('input[type=checkbox]').setValue(true);
        await button(w, 'Offers').trigger('click');
        await button(w, 'Outreach').trigger('click');
        await button(w, 'Agency pilot').trigger('click');
        await button(w, 'Continue to Audience').trigger('click');
        expect(
            (w.find('input[type=checkbox]').element as HTMLInputElement)
                .checked,
        ).toBe(true);
    });
});
