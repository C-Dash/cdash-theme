# Browse Pane Dynamics

Here are some wishes related to browse pane dynamics:  

Use the same responsive grid style applied to the resource-values, and apply them to a div on show-phtml below the page blocks.  The cells in this grid should carry labels and values as follows:

Citation :
Cambridge Historical Commission, Digital Architectural Survey and History.
Rights :
Rights status not evaluated.


I want to create a sticky div a the top of the show page.  This div will stay at the top of the Browse Pane as the contents of the show page are scrolled.  This div will contain the Item Title, Below the title a label reflecting the class of the item (Document or Place) note title case for these, Then in the same line, I want the Page count (from document item properties) for Document Items, and the Document Count (computed dynamically) for Place Items.  Later we will add breadcrumbs above the title, but not yet.  Let mem know if any of this is going to be very complicated.  





## Breadcrumbs Bar

Lets brainstorm about user navigation within the browse pane.  I think it would be cool to have a breadcrumb or two at the top of the fixed title div on the show page, which would help the user to get back to a the previous page.

If the previous page was a place, then the breadcrumb string "Return to [placeItem]", and the click takes you back to the place page at the page and scroll depth of your last click.  Not sure if the scroll-indexing can be done easily, please let me know.

If the previous page was Search Results, then the text would be "Return to Search Results" with the same page and scroll index as the user left that item set page.  

And if the previous page was a folder (item set) listing, the breadcrumb text would be "Return to [folder title]" — e.g. "Return to 105 Spring St East End Union". (Originally "Return to Folder Listing"; changed to the folder's own name, which says where the link goes. "Folder Listing" remains the fallback if the title can't be read.)

## Reveal Browse Pane, if necessary, on Marker or Nav-Bar Click.

If the Browse pane is collapsed and the use clicks a map marker or nav-bar link, the browse pane should reveal itself to half of its full extent with the same animated behavior as clicking the concealed browse pane's pull-tab. 

