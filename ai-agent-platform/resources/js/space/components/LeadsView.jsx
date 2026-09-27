import ContactsView from './ContactsView';

/** Legacy /space/contacts/leads (and old ?view=leads) open Contacts with the Leads section. */
export default function LeadsView() {
    return <ContactsView initialSection="leads" />;
}
