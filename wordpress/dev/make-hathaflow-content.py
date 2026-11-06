#!/usr/bin/env python3
"""Writes wordpress/olivia-yoga/demo/content-hathaflow.json: the Hathaflow demo studio (English, sample data).

Hathaflow is the booking system (theme + Olivia Studio plugin) shown as a product demo: a made-up studio with
sample classes, timetable, prices, FAQs and three journal posts. No Olivia content, no testimonials, no reviews;
every page says it is a demo (the theme's demo bar). Edit the copy here and run:  python3 wordpress/dev/make-hathaflow-content.py
"""
import json
import os

HERE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(HERE, 'olivia-yoga', 'demo', 'content-hathaflow.json')

pages = {
    'home': {
        'title': 'Home', 'excerpt': '', 'seo_title': '', 'seo_desc': '',
        'content': '<h2>A small studio with a big timetable</h2>\n'
                   '<p>Hathaflow is a sample yoga studio. It is here to show what the booking system does: a weekly '
                   'timetable with live free places, booking and payment on the site, class passes and memberships, '
                   'private and online sessions, gift cards, reminders and a student account.</p>\n'
                   '<p>Click around as a student would: open a class, book it, try the account. Payments run in test '
                   'mode, so nothing is charged.</p>',
    },
    'about': {
        'title': 'About the studio', 'excerpt': 'Hathaflow is a demo studio for the booking system: what it shows and how to try it.',
        'seo_title': 'About the Hathaflow demo studio', 'seo_desc': 'Hathaflow is a demo yoga studio that shows a complete booking system: timetable, booking, payments, passes, memberships, private and online sessions.',
        'content': '<h2>A demo studio</h2>\n'
                   '<p>Hathaflow is not a real studio. The classes, times, prices and pictures are examples, put here '
                   'so that you can see the booking system working with real-looking content.</p>\n'
                   '<h2>What you can try</h2>\n'
                   '<ul>\n<li>Book a group class from the <a href="{{page:schedule-pricing}}">timetable</a>, with a drop-in or a pass.</li>\n'
                   '<li>Buy a class pass or a membership (test payment).</li>\n'
                   '<li><a href="{{private}}">Request a private session</a>: the studio answers with a time and a price.</li>\n'
                   '<li>Join the waiting list of a full class.</li>\n'
                   '<li>Open your account: bookings, passes, membership, history.</li>\n</ul>\n'
                   '<h2>For the studio</h2>\n'
                   '<p>Behind the site there is a studio admin: weekly class templates, class lists and check-in, '
                   'payments and income, passes and memberships, private session requests, coupons, gift cards, '
                   'newsletters and email templates. Online classes get their Zoom links automatically.</p>',
    },
    'classes': {
        'title': 'Classes', 'excerpt': 'Hatha flow, slow flow and live online classes: the pace changes, the care stays the same.',
        'seo_title': 'Yoga classes | Hathaflow demo studio', 'seo_desc': 'Hatha flow, slow flow and live online yoga classes at the Hathaflow demo studio.',
        'content': '<p>Every class has breathwork, strength, stretching, balance and a long relaxation at the end. '
                   'What changes is the pace.</p>\n[oy_classes]\n'
                   '<h2>Which class should I choose?</h2>\n'
                   '<p><strong>New to yoga?</strong> Start with <a href="{{class:slow-flow}}">Slow Flow</a>, or book a '
                   '<a href="{{page:private-yoga}}">private session</a> to learn the basics one-on-one.</p>\n'
                   '<p><strong>Already active?</strong> <a href="{{class:hatha-flow}}">Hatha Flow</a> will keep you busy.</p>\n'
                   '<p><strong>At home tonight?</strong> Join <a href="{{class:online-yoga}}">online</a>, live.</p>',
    },
    'private-yoga': {
        'title': 'Private sessions', 'excerpt': 'One-on-one yoga in the studio or online, planned around your body and your goals.',
        'seo_title': 'Private yoga sessions | Hathaflow demo studio', 'seo_desc': 'Request a private yoga session in the studio or online: the studio replies with a time and a price you can pay in one click.',
        'content': '<h2>A class for one</h2>\n'
                   '<p>In a private session the class is planned for one person: your shoulders after a long week at the '
                   'desk, the knee you are careful with, the calm you would like to find. 60, 75 or 90 minutes, in the '
                   'studio or online.</p>\n'
                   '<h2>How it works</h2>\n'
                   '<ol>\n<li>Send a request with a few times that suit you.</li>\n'
                   '<li>The studio replies with an offer: a time and a price.</li>\n'
                   '<li>Pay with one click, or use a private session from a pack.</li>\n</ol>',
    },
    'schedule-pricing': {
        'title': 'Schedule & Pricing', 'excerpt': 'The weekly timetable with free places, drop-in prices, class passes, memberships and gift cards. Sample prices.',
        'seo_title': 'Schedule & prices | Hathaflow demo studio', 'seo_desc': 'Weekly yoga timetable with live free places, drop-in prices, class passes, memberships, private sessions and gift cards (sample prices).',
        'content': '<h2>Weekly classes</h2>\n[oy_schedule]\n'
                   '<p>Book ahead: places are counted live. Online classes are marked, the link comes by email.</p>\n'
                   '<h2>Prices</h2>\n<p>Sample prices for the demo.</p>\n[oy_pricing]\n'
                   '<h2 id="gift-cards">Gift cards</h2>\n'
                   '<p>Give a class pass or a private session. The gift card is emailed with a code that is redeemed in the account.</p>\n'
                   '<p>[oy_gift_link]</p>\n'
                   '<h2>Good to know</h2>\n'
                   '<p><strong>Cancelling:</strong> up to 12 hours before a group class the class goes back on your pass. '
                   'Later cancellations and no-shows use the class.</p>\n'
                   '<p><strong>What to bring:</strong> comfortable clothes and water. Mats are provided.</p>',
    },
    'faq': {
        'title': 'FAQ', 'excerpt': 'Answers about booking, passes, cancelling, online classes and what to bring.',
        'seo_title': 'FAQ | Hathaflow demo studio', 'seo_desc': 'Answers about booking, class passes, memberships, cancelling, online classes and what to bring.',
        'content': '[oy_faq]\n<p>Can\'t find your question? <a href="{{page:contact}}">Send a message</a>.</p>',
    },
    'contact': {
        'title': 'Contact', 'excerpt': 'Questions about classes, private sessions or gift cards: send a message.',
        'seo_title': 'Contact | Hathaflow demo studio', 'seo_desc': 'Send the Hathaflow demo studio a message about classes, private sessions or gift cards.',
        'content': '<p>Questions about a class, a private session or a gift card? Write a few lines. On the demo site '
                   'messages go to a test inbox.</p>\n[oy_contact_form]',
    },
    'journal': {
        'title': 'Journal', 'excerpt': 'Short notes on practice, breath and how classes work.',
        'seo_title': 'Journal | Hathaflow demo studio', 'seo_desc': 'Short notes on yoga practice, breathing and how classes work at the Hathaflow demo studio.',
        'content': '',
    },
}

classes = {
    'hatha-flow': {
        'title': 'Hatha Flow', 'excerpt': 'The dynamic class: breath-led sequences, strength, balance and a flow to get lost in.',
        'duration': '75 min', 'lang': 'English', 'seo_title': 'Hatha Flow yoga class | Hathaflow demo studio',
        'photo': 'studio-group-flow', 'aside': 'deck-down-dog', 'link': '',
        'content': '<h2>What happens in class</h2>\n<p>We arrive with a few minutes of breathing, warm up with sun '
                   'salutations, build strength in standing sequences and finish with balance work and a long '
                   'relaxation.</p>\n<h2>Who it is for</h2>\n<p>For people who move regularly and like a class that '
                   'keeps them busy. Every pose has an easier and a harder version.</p>',
    },
    'slow-flow': {
        'title': 'Slow Flow', 'excerpt': 'The gentle class: longer holds, more time for breath, open to every level.',
        'duration': '60 min', 'lang': 'English', 'seo_title': 'Slow Flow yoga class | Hathaflow demo studio',
        'photo': 'garden-class-lunges', 'aside': '', 'link': '',
        'content': '<h2>What happens in class</h2>\n<p>The same building blocks as Hatha Flow, at a slower pace: '
                   'longer holds, more time to notice, plenty of rest.</p>\n<h2>Who it is for</h2>\n<p>Beginners, '
                   'people coming back after a break, and anyone who wants a quieter evening.</p>',
    },
    'online-yoga': {
        'title': 'Online Yoga', 'excerpt': 'Selected classes live on Zoom, with the same booking and the same passes.',
        'duration': '60 min', 'lang': 'English', 'seo_title': 'Live online yoga classes | Hathaflow demo studio',
        'photo': 'online-yoga-living-room', 'aside': '', 'link': '',
        'content': '<h2>A live class at home</h2>\n<p>Online classes are live: the teacher sees the group and adjusts '
                   'the class. The Zoom link arrives by email after booking and waits in your account.</p>\n'
                   '<h2>What you need</h2>\n<p>A mat, a quiet corner and a device with a camera.</p>',
    },
    'private-yoga': {
        'title': 'Private Yoga', 'excerpt': 'One-on-one in the studio or online, planned for your body and your goals.',
        'duration': '60, 75 or 90 min', 'lang': 'English', 'seo_title': '',
        'photo': 'private-side-angle', 'aside': '', 'link': 'private-yoga',
        'content': '<p>A private session is planned around one person. Request a time and the studio replies with an offer.</p>',
    },
}

faqs = [
    {'home': '1', 'q': 'Do I need experience?', 'a': 'No. Slow Flow is open to every level, and every pose has an easier version. Tell the teacher before class if you have an injury.'},
    {'home': '1', 'q': 'How do I book?', 'a': 'Choose a class in the timetable and book it with a drop-in, a class pass or your membership. You get a confirmation with a calendar file and a reminder before class.'},
    {'home': '1', 'q': 'Can I cancel?', 'a': 'Yes, in your account. Up to 12 hours before a group class the class goes back on your pass. Later cancellations and no-shows use the class.'},
    {'home': '1', 'q': 'How do online classes work?', 'a': 'They are live on Zoom. The link comes by email after booking and is also in your account. Join a few minutes early.'},
    {'home': '', 'q': 'What happens if a class is full?', 'a': 'Join the waiting list. If a place opens, students with a pass or membership are booked in automatically; the others get an email.'},
    {'home': '', 'q': 'Can I bring a friend?', 'a': 'Yes: book a place for a guest with your pass or pay for their ticket. Your guest gets their own confirmation.'},
    {'home': '', 'q': 'How long are class passes valid?', 'a': 'The 5-class pass is valid for 90 days, the online 10-class pass for 6 months. Memberships renew monthly and can be cancelled any time.'},
    {'home': '', 'q': 'Is this a real studio?', 'a': 'No. Hathaflow is a demo of the booking system. The classes, times, prices and pictures are examples and payments run in test mode.'},
]

posts = {
    'how-to-choose-your-first-class': {
        'title': 'How to choose your first class', 'excerpt': 'Slow or dynamic, studio or online: a short guide for the first visit.',
        'content': '<p>The first class is easier when you know what to expect. If you are new or coming back after a '
                   'break, start with Slow Flow. If you already move regularly, Hatha Flow will feel right. Can\'t make '
                   'it to the studio? Join online.</p>\n<h2>Before you come</h2>\n<p>Eat lightly, bring water and '
                   'arrive ten minutes early so there is time to say hello.</p>',
        'category': ['Practice'], 'days_ago': 6, 'photo': 'deck-down-dog',
    },
    'breathing-before-bed': {
        'title': 'A breathing exercise before bed', 'excerpt': 'Four counts in, six counts out: a two-minute exercise for a calmer evening.',
        'content': '<p>A longer exhale tells the body it is safe to slow down. Sit or lie comfortably, breathe in '
                   'through the nose for four counts and out for six. Ten rounds are enough.</p>',
        'category': ['Breath'], 'days_ago': 14, 'photo': 'olivia-childs-pose',
    },
    'what-a-class-pass-is-for': {
        'title': 'Drop-in, pass or membership?', 'excerpt': 'Which option suits a weekly practice, and which suits the odd class.',
        'content': '<p>A drop-in suits the occasional class. A 5-class pass costs less per class and works for any '
                   'group class. A membership suits a weekly habit: it renews monthly and can be cancelled any time.</p>',
        'category': ['Studio'], 'days_ago': 25, 'photo': 'garden-class-lunges',
    },
}

sessions = [
    {'day': 1, 'start': '18:00', 'end': '19:00', 'class': 'slow-flow', 'location': 'Main studio', 'lang': 'en', 'format': 'studio', 'note': ''},
    {'day': 2, 'start': '18:00', 'end': '19:15', 'class': 'hatha-flow', 'location': 'Main studio', 'lang': 'en', 'format': 'hybrid', 'note': ''},
    {'day': 3, 'start': '07:30', 'end': '08:30', 'class': 'online-yoga', 'location': 'Online (Zoom)', 'lang': 'en', 'format': 'online', 'note': ''},
    {'day': 4, 'start': '18:30', 'end': '19:30', 'class': 'slow-flow', 'location': 'Main studio', 'lang': 'en', 'format': 'studio', 'note': ''},
    {'day': 6, 'start': '09:00', 'end': '10:15', 'class': 'hatha-flow', 'location': 'Main studio', 'lang': 'en', 'format': 'studio', 'note': ''},
]

product = lambda name, kind, credits, days, cents, desc, feat, sort, **kw: dict(  # noqa: E731
    {'name': name, 'kind': kind, 'credits': credits, 'validity_days': days, 'price_cents': cents, 'description': desc,
     'features': feat, 'featured': 0, 'giftable': 0, 'active': 1, 'duration_min': 0, 'sort': sort}, **kw)
products = {
    'intro-2': product('New student intro: 2 classes', 'intro', 2, 30, 3000, 'Two group classes within 30 days.', '2 group classes\nUse within 30 days\nNew students only', 5, active=0),
    'pack-5': product('5-class pass', 'pack', 5, 90, 11000, 'Five group classes, in the studio or online.', 'Use for any group class\nValid for 90 days\nAlso as a gift card', 10, featured=1, giftable=1),
    'online-10': product('Online 10-class pass', 'online_pack', 10, 180, 5000, 'Ten live online classes.', '10 live online classes\nValid for 6 months', 11),
    'unlimited-monthly': product('Unlimited monthly', 'membership', 0, 0, 11900, 'Every group class, every week.', 'Unlimited group classes\nIn the studio or online\nCancel any time', 12, interval='month', interval_count=1, classes_per_period=0),
    'four-a-month': product('4 classes a month', 'membership', 0, 0, 8500, 'A steady weekly practice.', '4 group classes each month\nCancel any time', 13, interval='month', interval_count=1, classes_per_period=4),
    'private-60': product('Private session, 60 minutes', 'private_single', 1, 365, 9000, 'One-on-one in the studio or online.', 'In the studio or online\nPlanned around your goals', 20, giftable=1, duration_min=60, online_price_cents=7000),
    'private-75': product('Private session, 75 minutes', 'private_single', 1, 365, 11000, '', '', 21, duration_min=75, online_price_cents=8500),
    'private-90': product('Private session, 90 minutes', 'private_single', 1, 365, 13000, '', '', 22, duration_min=90, online_price_cents=10000),
    'private-5': product('5 private sessions', 'private_pack', 5, 365, 40000, 'Five 60-minute private sessions.', '5 × 60 minutes\nA plan that builds session by session', 30, giftable=1, duration_min=60),
}

data = {
    '_note': 'Hathaflow demo studio (sample data). Generated by wordpress/dev/make-hathaflow-content.py – edit there.',
    'setup': {
        'options': {'blogname': 'Hathaflow', 'blogdescription': 'Yoga studio booking demo', 'timezone_string': 'America/New_York'},
        'studio': {
            'languages': 'en', 'default_lang': 'en', 'currency': 'usd', 'online_only': 0, 'owner_name': 'the studio',
            'email_from_name': 'Hathaflow', 'online_price_cents': 1500,
            'waiver_text': 'I understand that yoga includes physical movement and that there is a risk of injury. I confirm I am in good health or have my doctor\'s approval, and I will tell the teacher before class about any injury, pregnancy or health condition. I listen to my body and rest when I need to. This is a demo studio.',
            'cancel_policy': 'Cancel up to 12 hours before a group class and the class goes back on your pass. Later cancellations and no-shows use the class. Private sessions can be moved or cancelled up to 24 hours before.',
            'private_note': 'The first private session starts with a short conversation about your goals, so plan for about 15 extra minutes.',
        },
        'products': products,
        'locations': [{'id': 'main-studio', 'name': 'Main studio', 'address': 'Sample address', 'min_people': 2, 'decide_hours': 3}],
    },
    'menu': {'classes': 'Classes', 'schedule-pricing': 'Schedule & pricing', 'private-yoga': 'Private sessions', 'about': 'About', 'faq': 'FAQ', 'journal': 'Journal', 'contact': 'Contact'},
    'pages': pages, 'classes': classes, 'faqs': faqs, 'posts': posts, 'moments': [], 'sessions': sessions, 'events': {},
}
json.dump(data, open(OUT, 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
print('→', OUT)
